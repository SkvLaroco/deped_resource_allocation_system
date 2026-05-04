import pandas as pd
from prophet import Prophet
import sys
import json
import os
import math
from logger_config import setup_logger

logger = setup_logger(__name__)

try:
    logger.info("Starting per-school forecast generation")

    # ── Load config ────────────────────────────────────────────────────────
    config_file = "config.json"
    defaults = {
        'class_size': 40,
        'academic_ratio': 0.65,
        'tvl_ratio': 0.35,
        'forecast_years': 3,
        'sections_per_teacher': 1.5,
        'confidence_level': 0.95
    }
    if os.path.exists(config_file):
        with open(config_file, 'r') as f:
            cfg = json.load(f)
        config = {**defaults, **cfg}
    else:
        config = defaults
        logger.info("config.json not found — using DepEd defaults")

    CLASS_SIZE           = int(config['class_size'])
    ACADEMIC_RATIO       = float(config['academic_ratio'])
    TVL_RATIO            = 1.0 - ACADEMIC_RATIO
    SECTIONS_PER_TEACHER = float(config['sections_per_teacher'])
    FORECAST_YEARS       = int(config['forecast_years'])
    INTERVAL_WIDTH       = float(config.get('confidence_level', 0.95))

    logger.info(f"Config: class_size={CLASS_SIZE}, academic_ratio={ACADEMIC_RATIO:.2f}, "
                f"tvl_ratio={TVL_RATIO:.2f}, sections_per_teacher={SECTIONS_PER_TEACHER}, "
                f"forecast_years={FORECAST_YEARS}")

    # ── Load per-school dataset ────────────────────────────────────────────
    input_file = "NCR_SHS_Per_School_Enrollees.csv"
    if not os.path.exists(input_file):
        raise FileNotFoundError(
            f"{input_file} not found. Please upload the per-school dataset first.")

    df = pd.read_csv(input_file)
    logger.info(f"Loaded {len(df)} rows from {input_file}")

    # Validate required columns
    required_cols = ['School_ID', 'School_Name', 'Year', 'Total_Enrollees']
    for col in required_cols:
        if col not in df.columns:
            raise ValueError(f"Missing required column: {col}")

    # Optional columns with graceful defaults
    if 'City' not in df.columns:
        df['City'] = ''
    if 'Academic_Enrollees' not in df.columns:
        df['Academic_Enrollees'] = (df['Total_Enrollees'] * ACADEMIC_RATIO).round(0).astype(int)
    if 'TVL_Enrollees' not in df.columns:
        df['TVL_Enrollees'] = (df['Total_Enrollees'] * TVL_RATIO).round(0).astype(int)

    # Clean numeric columns
    df['Year']            = pd.to_numeric(df['Year'],            errors='coerce')
    df['Total_Enrollees'] = pd.to_numeric(df['Total_Enrollees'], errors='coerce')
    df = df.dropna(subset=['Year', 'Total_Enrollees'])
    df = df[df['Total_Enrollees'] > 0]
    df['Year'] = df['Year'].astype(int)

    schools = df['School_ID'].unique()
    logger.info(f"Found {len(schools)} unique schools")

    # ── Forecast per school ────────────────────────────────────────────────
    all_results  = []
    school_mapes = []
    skipped      = 0

    for school_id in schools:
        sdf         = df[df['School_ID'] == school_id].copy().sort_values('Year')
        school_name = str(sdf['School_Name'].iloc[0])
        city        = str(sdf['City'].iloc[0]) if 'City' in sdf.columns else ''

        # Need at least 2 data points for Prophet
        if len(sdf) < 2:
            logger.warning(f"Skipping '{school_name}' — only {len(sdf)} data point(s)")
            skipped += 1
            continue

        # Derive per-school academic/TVL ratio from historical data when available
        acad_sum  = pd.to_numeric(sdf['Academic_Enrollees'], errors='coerce').sum()
        total_sum = pd.to_numeric(sdf['Total_Enrollees'],    errors='coerce').sum()
        if acad_sum > 0 and total_sum > 0:
            school_acad_ratio = acad_sum / total_sum
        else:
            school_acad_ratio = ACADEMIC_RATIO
        school_tvl_ratio = 1.0 - school_acad_ratio

        # Prepare Prophet input
        prophet_df = sdf[['Year', 'Total_Enrollees']].copy()
        prophet_df['ds'] = pd.to_datetime(prophet_df['Year'].astype(int), format='%Y')
        prophet_df = prophet_df.rename(columns={'Total_Enrollees': 'y'})[['ds', 'y']]
        prophet_df = prophet_df.dropna()

        # Train model
        try:
            model = Prophet(
                yearly_seasonality=False,
                weekly_seasonality=False,
                daily_seasonality=False,
                interval_width=INTERVAL_WIDTH
            )
            model.fit(prophet_df)
        except Exception as model_err:
            logger.warning(f"Model failed for '{school_name}': {model_err}")
            skipped += 1
            continue

        # Compute in-sample MAPE for this school
        hist_pred = model.predict(prophet_df[['ds']])
        merged    = prophet_df.merge(hist_pred[['ds', 'yhat']], on='ds', how='left')
        merged    = merged[merged['y'] > 0]
        if len(merged) > 0:
            school_mape = (abs(merged['y'] - merged['yhat']) / merged['y']).mean() * 100
            school_mapes.append(school_mape)

        # Generate future forecast
        last_year    = prophet_df['ds'].dt.year.max()
        future_years = [last_year + i for i in range(1, FORECAST_YEARS + 1)]
        future_dates = [pd.Timestamp(year=y, month=1, day=1) for y in future_years]
        future       = pd.DataFrame({'ds': future_dates})
        forecast     = model.predict(future)

        for _, row in forecast.iterrows():
            yr    = int(row['ds'].year)
            proj  = max(0, int(round(row['yhat'])))
            lower = max(0, int(round(row['yhat_lower'])))
            upper = max(0, int(round(row['yhat_upper'])))

            acad_students = proj * school_acad_ratio
            tvl_students  = proj * school_tvl_ratio

            acad_rooms    = math.ceil(acad_students / CLASS_SIZE) if CLASS_SIZE > 0 else 0
            tvl_rooms     = math.ceil(tvl_students  / CLASS_SIZE) if CLASS_SIZE > 0 else 0
            acad_teachers = math.ceil(acad_rooms / SECTIONS_PER_TEACHER) if SECTIONS_PER_TEACHER > 0 else 0
            tvl_teachers  = math.ceil(tvl_rooms  / SECTIONS_PER_TEACHER) if SECTIONS_PER_TEACHER > 0 else 0

            all_results.append({
                'School_ID':           school_id,
                'School_Name':         school_name,
                'City':                city,
                'Year':                yr,
                'Projected_Enrollees': proj,
                'Lower_Bound':         lower,
                'Upper_Bound':         upper,
                'Academic_Classrooms': acad_rooms,
                'TVL_Classrooms':      tvl_rooms,
                'Academic_Teachers':   acad_teachers,
                'TVL_Teachers':        tvl_teachers
            })

    if not all_results:
        raise ValueError(
            f"No schools could be forecasted. "
            f"{skipped} school(s) skipped (need at least 2 years of data per school).")

    logger.info(f"Forecasted {len(schools) - skipped} schools, skipped {skipped}")

    # ── Save output ────────────────────────────────────────────────────────
    out_df = pd.DataFrame(all_results)
    out_df = out_df.sort_values(['Year', 'School_Name']).reset_index(drop=True)
    out_df.to_csv("forecast_per_school.csv", index=False)
    logger.info(f"Saved forecast_per_school.csv ({len(out_df)} rows, {FORECAST_YEARS} years per school)")

    # ── Save accuracy metrics ──────────────────────────────────────────────
    overall_mape     = float(sum(school_mapes) / len(school_mapes)) if school_mapes else 0.0
    overall_accuracy = round(100.0 - overall_mape, 2)

    accuracy_metrics = {
        'mape':         round(overall_mape, 2),
        'accuracy':     overall_accuracy,
        'school_count': int(len(schools) - skipped),
        'data_points':  int(len(df)),
        'mode':         'per_school'
    }
    with open('forecast_accuracy.json', 'w') as f_acc:
        json.dump(accuracy_metrics, f_acc)
    logger.info(f"Accuracy: MAPE={overall_mape:.2f}% | Accuracy={overall_accuracy}%")

    print("SUCCESS: Per-school forecast generated.")
    logger.info("Per-school forecast generation completed successfully")

except FileNotFoundError as e:
    print(f"ERROR: {e}")
    logger.error(str(e))
    sys.exit(1)

except ValueError as e:
    print(f"ERROR: {e}")
    logger.error(str(e))
    sys.exit(1)

except Exception as e:
    print(f"ERROR: Unexpected error: {e}")
    logger.error(f"Unexpected error: {e}", exc_info=True)
    sys.exit(1)
