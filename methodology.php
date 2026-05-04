<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}
$timeout_duration = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset(); session_destroy();
    header('Location: index.php'); exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

// Load config for current parameter values
$config_file = 'config.json';
$config = file_exists($config_file)
    ? json_decode(file_get_contents($config_file), true)
    : [];
$class_size          = $config['class_size']           ?? 40;
$academic_ratio      = $config['academic_ratio']        ?? 0.65;
$tvl_ratio           = $config['tvl_ratio']             ?? 0.35;
$sections_per_teacher= $config['sections_per_teacher']  ?? 1.5;
$forecast_years      = $config['forecast_years']        ?? 3;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Methodology & Formula Basis — NCR SHS Forecast</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .method-wrapper {
            max-width: 1100px;
            margin: 0 auto;
            padding: 32px 28px 56px;
            position: relative;
            z-index: 1;
        }

        /* Section cards */
        .method-card {
            background: linear-gradient(145deg, rgba(255,255,255,0.98) 0%, rgba(242,247,255,0.97) 100%);
            border-radius: var(--radius-lg);
            padding: 32px 36px;
            margin-bottom: 24px;
            box-shadow: var(--card-3d);
            border: 1px solid rgba(255,255,255,0.88);
            position: relative;
            overflow: hidden;
        }

        .method-card::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 5px;
            border-radius: var(--radius-lg) 0 0 var(--radius-lg);
        }

        .mc-navy::before  { background: linear-gradient(180deg, var(--navy), var(--navy-mid)); }
        .mc-gold::before  { background: linear-gradient(180deg, var(--gold), #F59E0B); }
        .mc-green::before { background: linear-gradient(180deg, var(--green), #22C55E); }
        .mc-purple::before{ background: linear-gradient(180deg, var(--purple), #7C3AED); }
        .mc-teal::before  { background: linear-gradient(180deg, var(--teal), #0E91A6); }

        .method-card h2 {
            font-family: var(--font-display);
            font-size: 1.2em;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 6px;
            padding: 0;
            border: none;
        }

        .method-card .section-number {
            font-size: 0.72em;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .method-card p {
            font-size: 0.96em;
            line-height: 1.75;
            color: var(--text-body);
            margin-bottom: 14px;
        }

        /* Formula display */
        .formula-display {
            background: linear-gradient(145deg, var(--navy-deep) 0%, var(--navy) 100%);
            border-radius: var(--radius-md);
            padding: 20px 24px;
            margin: 18px 0;
            box-shadow: var(--shadow-lg), inset 0 1px 0 rgba(255,255,255,0.1);
        }

        .formula-display .formula-label {
            font-size: 0.72em;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.50);
            font-weight: 700;
            margin-bottom: 12px;
        }

        .formula-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .formula-row:last-child { margin-bottom: 0; }

        .formula-lhs {
            color: var(--gold);
            font-family: 'Courier New', monospace;
            font-size: 0.96em;
            font-weight: 700;
            min-width: 240px;
        }

        .formula-eq {
            color: rgba(255,255,255,0.40);
            font-family: 'Courier New', monospace;
            font-size: 1em;
        }

        .formula-rhs {
            color: rgba(255,255,255,0.88);
            font-family: 'Courier New', monospace;
            font-size: 0.92em;
        }

        /* Source citation */
        .source-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(135deg, var(--navy-light) 0%, rgba(212,168,67,0.08) 100%);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 8px 14px;
            font-size: 0.82em;
            color: var(--text-body);
            margin: 6px 6px 6px 0;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.18s;
        }

        .source-tag:hover {
            background: var(--navy-light);
            border-color: var(--navy);
            color: var(--navy);
        }

        .source-tag .src-icon { font-size: 1em; }
        .source-tag .src-type {
            font-size: 0.70em;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            color: var(--text-muted);
        }

        .sources-row { display: flex; flex-wrap: wrap; gap: 0; margin-top: 16px; }

        /* Live parameter display */
        .param-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin: 18px 0;
        }

        .param-item {
            background: linear-gradient(145deg, var(--surface) 0%, var(--navy-light) 100%);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            box-shadow: var(--shadow-sm), inset 0 1px 0 rgba(255,255,255,0.6);
        }

        .param-label {
            font-size: 0.72em;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .param-value {
            font-family: var(--font-display);
            font-size: 1.55em;
            font-weight: 800;
            color: var(--navy);
        }

        .param-basis {
            font-size: 0.78em;
            color: var(--text-muted);
            margin-top: 4px;
            font-weight: 500;
        }

        /* Example walkthrough */
        .example-box {
            background: linear-gradient(135deg, rgba(212,168,67,0.06) 0%, rgba(11,42,107,0.04) 100%);
            border: 1px solid var(--border);
            border-left: 4px solid var(--gold);
            border-radius: var(--radius-md);
            padding: 18px 22px;
            margin: 16px 0;
        }

        .example-box .example-title {
            font-size: 0.80em;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            color: var(--gold);
            margin-bottom: 12px;
        }

        .example-step {
            display: flex;
            gap: 12px;
            align-items: baseline;
            margin-bottom: 8px;
            font-size: 0.92em;
        }

        .example-step .step-num {
            background: var(--navy);
            color: var(--white);
            width: 20px; height: 20px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.72em;
            font-weight: 800;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .example-step .step-text { color: var(--text-body); line-height: 1.6; }
        .example-step .step-text code { font-size: 0.90em; }

        /* Limitation cards */
        .limitation-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 16px;
        }

        @media (max-width: 700px) { .limitation-grid { grid-template-columns: 1fr; } }

        .limit-item {
            background: var(--surface);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            font-size: 0.88em;
            color: var(--text-body);
            line-height: 1.6;
        }

        .limit-item strong {
            display: block;
            color: var(--text-primary);
            font-weight: 700;
            margin-bottom: 4px;
        }

        /* Disclaimer banner */
        .disclaimer-banner {
            background: linear-gradient(135deg, var(--amber-bg) 0%, #FEF3C7 100%);
            border: 1px solid #FDE68A;
            border-left: 5px solid var(--amber);
            border-radius: var(--radius-md);
            padding: 18px 22px;
            font-size: 0.90em;
            color: #78350F;
            line-height: 1.7;
            margin-bottom: 28px;
        }

        .disclaimer-banner strong { font-weight: 800; }

        /* Timeline for forecasting model section */
        .model-steps {
            display: flex;
            flex-direction: column;
            gap: 0;
            margin: 16px 0;
        }

        .model-step {
            display: flex;
            gap: 18px;
            position: relative;
            padding-bottom: 20px;
        }

        .model-step:last-child { padding-bottom: 0; }

        .step-dot {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: var(--gold);
            font-weight: 800;
            font-size: 0.82em;
            flex-shrink: 0;
            box-shadow: 0 3px 8px rgba(11,42,107,0.3);
            position: relative;
            z-index: 1;
        }

        .model-step:not(:last-child)::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 32px;
            bottom: 0;
            width: 2px;
            background: linear-gradient(180deg, var(--border) 0%, transparent 100%);
        }

        .step-content { padding-top: 4px; }
        .step-content h4 { font-family: var(--font-display); font-size: 0.95em; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }
        .step-content p  { font-size: 0.88em; color: var(--text-muted); margin: 0; line-height: 1.6; }
    </style>
</head>
<body>

<?php $current_page = 'methodology'; include 'nav.php'; ?>

<div class="method-wrapper">

    <!-- Hero -->
    <div class="page-hero" style="margin-bottom:24px;">
        <div class="page-hero-text">
            <h1>Methodology & Formula Basis</h1>
            <p>Transparency documentation — data sources, computation logic, and known limitations</p>
        </div>
        <div class="page-hero-badge">
            <div class="badge-label">Last Updated</div>
            <div class="badge-value" style="font-size:1.1em; letter-spacing:0;">SY 2025–26</div>
            <div class="badge-sub">Based on DepEd planning parameters</div>
        </div>
    </div>

    <!-- Disclaimer -->
    <div class="disclaimer-banner">
        <strong>⚠️ Important Disclaimer:</strong> All projections on this system are
        <strong>planning estimates only</strong>, derived from statistical time-series modeling
        applied to historical NCR SHS enrollment data. They are not official DepEd figures.
        Resource allocation figures are computed using DepEd's published planning parameters
        and should be used as a baseline reference for budget planning discussions —
        not as definitive procurement targets. Actual needs depend on per-school conditions,
        infrastructure assessments, and official DepEd allocation decisions.
    </div>

    <!-- Section 1: Class Size -->
    <div class="method-card mc-navy">
        <div class="section-number">Parameter 01</div>
        <h2>Class Size — 40 Students per Classroom</h2>
        <p>
            The standard of <strong>40 students per classroom</strong> for Senior High School is DepEd's
            official planning parameter, consistently applied in national infrastructure planning and
            classroom shortage computations. DepEd Undersecretary Jesus Mateo confirmed that
            <em>"for Grades 5–10 and SHS, the planning parameter is 1:40"</em> — meaning one teacher
            handles up to 40 students, and one classroom is designed to accommodate one section of
            40 learners.
        </p>
        <p>
            This same ratio was used in the 2023 Senate hearing where DepEd reported a shortage of
            159,000 classrooms nationwide for SY 2023–2024, calculated against the 1:40 standard.
        </p>

        <div class="formula-display">
            <div class="formula-label">Classroom Computation</div>
            <div class="formula-row">
                <span class="formula-lhs">Academic Classrooms</span>
                <span class="formula-eq">=</span>
                <span class="formula-rhs">(Total Enrollees × Academic Ratio) ÷ <?php echo $class_size; ?></span>
            </div>
            <div class="formula-row">
                <span class="formula-lhs">TVL Classrooms</span>
                <span class="formula-eq">=</span>
                <span class="formula-rhs">(Total Enrollees × TVL Ratio) ÷ <?php echo $class_size; ?></span>
            </div>
        </div>

        <div class="param-grid">
            <div class="param-item">
                <div class="param-label">Current Class Size Setting</div>
                <div class="param-value"><?php echo $class_size; ?></div>
                <div class="param-basis">Students per section/classroom</div>
            </div>
        </div>

        <div class="sources-row">
            <a class="source-tag" href="https://www.pna.gov.ph/articles/1029281" target="_blank">
                <span class="src-icon">📰</span>
                <div>
                    <div>Philippine News Agency — Class size affects learning</div>
                    <div class="src-type">News report · March 2018</div>
                </div>
            </a>
            <a class="source-tag" href="https://www.rappler.com/philippines/deped-report-classroom-shortage-school-year-2023-2024/" target="_blank">
                <span class="src-icon">📰</span>
                <div>
                    <div>Rappler — Classroom shortage rises to 159,000</div>
                    <div class="src-type">News report · August 2023</div>
                </div>
            </a>
            <a class="source-tag" href="https://www.deped.gov.ph/2006/05/26/do-21-s-2006-guidelines-for-the-organization-of-classes/" target="_blank">
                <span class="src-icon">📋</span>
                <div>
                    <div>DepEd Order No. 21, s. 2006 — Class Organization Guidelines</div>
                    <div class="src-type">Official DepEd Order</div>
                </div>
            </a>
        </div>
    </div>

    <!-- Section 2: Academic/TVL Split -->
    <div class="method-card mc-gold">
        <div class="section-number">Parameter 02</div>
        <h2>Academic / TVL Track Enrollment Split — <?php echo round($academic_ratio*100,0); ?>% / <?php echo round($tvl_ratio*100,0); ?>%</h2>
        <p>
            The 65% Academic / 35% TVL split used in this system is a <strong>configurable planning
            estimate</strong> derived from observed NCR SHS enrollment patterns. It is not a fixed
            DepEd national mandate — actual track enrollment varies significantly by school,
            city, and school year.
        </p>
        <p>
            National DepEd data consistently shows that the Academic track (STEM, ABM, HUMSS, GAS)
            draws a larger share of SHS enrollees than TVL, particularly in urbanized regions like NCR
            where college-going rates are higher. Nationally, approximately <strong>83% of SHS graduates
            pursued higher education</strong> according to DepEd's own tracer study, which correlates
            with higher Academic track enrollment ratios in the NCR.
        </p>
        <p>
            <strong>This ratio is adjustable</strong> in the Admin → Settings panel. DepEd planners
            should calibrate this value against their school division's actual enrollment data
            from the Basic Education Information System (BEIS/EBEIS) before using this system
            for budget submissions.
        </p>

        <div class="formula-display">
            <div class="formula-label">Track Split Application</div>
            <div class="formula-row">
                <span class="formula-lhs">Academic Students</span>
                <span class="formula-eq">=</span>
                <span class="formula-rhs">Projected Enrollees × <?php echo round($academic_ratio*100,1); ?>%</span>
            </div>
            <div class="formula-row">
                <span class="formula-lhs">TVL Students</span>
                <span class="formula-eq">=</span>
                <span class="formula-rhs">Projected Enrollees × <?php echo round($tvl_ratio*100,1); ?>%</span>
            </div>
            <div class="formula-row">
                <span class="formula-lhs" style="color:rgba(255,255,255,0.45); font-size:0.82em;">Note: TVL ratio auto-set to 1 − Academic ratio, so both always sum to 100%</span>
            </div>
        </div>

        <div class="param-grid">
            <div class="param-item">
                <div class="param-label">Academic Track Ratio</div>
                <div class="param-value"><?php echo round($academic_ratio*100,1); ?>%</div>
                <div class="param-basis">Configurable in Admin Settings</div>
            </div>
            <div class="param-item">
                <div class="param-label">TVL Track Ratio</div>
                <div class="param-value"><?php echo round($tvl_ratio*100,1); ?>%</div>
                <div class="param-basis">Auto-computed (100% − Academic)</div>
            </div>
        </div>

        <div class="sources-row">
            <a class="source-tag" href="https://mb.com.ph/2023/5/13/dep-ed-creates-a-national-task-force-to-review-shs-program-find-out-why" target="_blank">
                <span class="src-icon">📰</span>
                <div>
                    <div>Manila Bulletin — DepEd National Tracer Study: 83% pursue higher ed</div>
                    <div class="src-type">News report · May 2023</div>
                </div>
            </a>
            <a class="source-tag" href="https://beis.deped.gov.ph" target="_blank">
                <span class="src-icon">🗄️</span>
                <div>
                    <div>DepEd BEIS / EBEIS — School enrollment data by track</div>
                    <div class="src-type">Official DepEd data system</div>
                </div>
            </a>
        </div>
    </div>

    <!-- Section 3: Teacher Computation -->
    <div class="method-card mc-green">
        <div class="section-number">Parameter 03</div>
        <h2>Teacher Requirements — <?php echo $sections_per_teacher; ?> Sections per Teacher</h2>
        <p>
            The teacher requirement formula is based on two DepEd principles working together:
        </p>
        <p>
            <strong>1. The 1:40 SHS planning parameter</strong> defines how many students one teacher
            handles per section (40 students = 1 section = 1 classroom). This is consistent across
            the system.
        </p>
        <p>
            <strong>2. The 6-hour daily teaching load limit</strong> under the Magna Carta for Public
            School Teachers (Republic Act 4670) means a teacher can teach multiple sections per day.
            In SHS class programming, DepEd's standard allocation is <strong>9 sections per 6 teachers</strong>,
            which gives 1.5 sections per teacher — meaning one teacher handles approximately 1.5
            sections throughout the day across different subjects or class groups.
        </p>
        <p>
            This produces teacher counts that are <strong>always lower than classroom counts</strong>,
            which reflects reality: a single teacher moves between classrooms rather than staying in
            one room all day.
        </p>

        <div class="formula-display">
            <div class="formula-label">Teacher Computation</div>
            <div class="formula-row">
                <span class="formula-lhs">Academic Teachers</span>
                <span class="formula-eq">=</span>
                <span class="formula-rhs">Academic Classrooms ÷ <?php echo $sections_per_teacher; ?></span>
            </div>
            <div class="formula-row">
                <span class="formula-lhs">TVL Teachers</span>
                <span class="formula-eq">=</span>
                <span class="formula-rhs">TVL Classrooms ÷ <?php echo $sections_per_teacher; ?></span>
            </div>
            <div class="formula-row">
                <span class="formula-lhs" style="color:rgba(255,255,255,0.45); font-size:0.82em;">
                    Result: Teachers &lt; Classrooms (always, by design)
                </span>
            </div>
        </div>

        <div class="param-grid">
            <div class="param-item">
                <div class="param-label">Sections per Teacher</div>
                <div class="param-value"><?php echo $sections_per_teacher; ?></div>
                <div class="param-basis">9 sections ÷ 6 teachers = 1.5</div>
            </div>
        </div>

        <div class="sources-row">
            <a class="source-tag" href="https://www.deped.gov.ph/2006/05/26/do-21-s-2006-guidelines-for-the-organization-of-classes/" target="_blank">
                <span class="src-icon">📋</span>
                <div>
                    <div>DepEd Order No. 21, s. 2006 — 6-hour teaching load per RA 4670</div>
                    <div class="src-type">Official DepEd Order</div>
                </div>
            </a>
            <a class="source-tag" href="https://www.pna.gov.ph/articles/1029281" target="_blank">
                <span class="src-icon">📰</span>
                <div>
                    <div>PNA — "SHS: factor in subject specialization of teachers"</div>
                    <div class="src-type">DepEd Usec. Mateo statement · 2018</div>
                </div>
            </a>
            <a class="source-tag" href="https://mb.com.ph/2018/03/20/deped-reaffirms-effort-to-achieve-ideal-class-size-teacher-student-ratio/" target="_blank">
                <span class="src-icon">📰</span>
                <div>
                    <div>Manila Bulletin — DepEd ideal class size and teacher ratio</div>
                    <div class="src-type">News report · March 2018</div>
                </div>
            </a>
        </div>
    </div>

    <!-- Section 4: Forecasting Model -->
    <div class="method-card mc-purple">
        <div class="section-number">Parameter 04</div>
        <h2>Forecasting Model — Facebook Prophet (Time-Series)</h2>
        <p>
            Enrollment projections are generated using <strong>Facebook Prophet</strong>, an open-source
            time-series forecasting library developed by Meta's Core Data Science team. Prophet is
            designed for data with strong trend components and is particularly robust with sparse
            annual data — making it appropriate for DepEd's year-by-year enrollment records.
        </p>

        <div class="model-steps">
            <div class="model-step">
                <div class="step-dot">1</div>
                <div class="step-content">
                    <h4>Data Input</h4>
                    <p>Historical NCR SHS enrollment CSV (Year, Total_Enrollees) is uploaded. The system validates column structure, data types, and year ranges before processing.</p>
                </div>
            </div>
            <div class="model-step">
                <div class="step-dot">2</div>
                <div class="step-content">
                    <h4>Model Training</h4>
                    <p>Prophet is fitted on the historical data with yearly/weekly/daily seasonality disabled (annual data has no sub-year seasonality). Interval width is set to 0.95, producing 95% confidence bounds.</p>
                </div>
            </div>
            <div class="model-step">
                <div class="step-dot">3</div>
                <div class="step-content">
                    <h4>Accuracy Validation (MAPE)</h4>
                    <p>The model predicts back on the historical data to compute <strong>Mean Absolute Percentage Error (MAPE)</strong>. Model Accuracy is reported as 100% − MAPE and displayed on the dashboard.</p>
                </div>
            </div>
            <div class="model-step">
                <div class="step-dot">4</div>
                <div class="step-content">
                    <h4>Forecast Generation</h4>
                    <p>Prophet projects enrollment for the next <?php echo $forecast_years; ?> school years, outputting point estimates (yhat) and 95% confidence intervals (yhat_lower, yhat_upper).</p>
                </div>
            </div>
            <div class="model-step">
                <div class="step-dot">5</div>
                <div class="step-content">
                    <h4>Resource Computation</h4>
                    <p>The projected enrollees are passed through the classroom and teacher formulas (Sections 1–3 above) to produce per-year resource allocation figures.</p>
                </div>
            </div>
        </div>

        <div class="sources-row">
            <a class="source-tag" href="https://facebook.github.io/prophet/" target="_blank">
                <span class="src-icon">🔬</span>
                <div>
                    <div>Facebook Prophet — Official Documentation</div>
                    <div class="src-type">Open-source library · Meta Research</div>
                </div>
            </a>
        </div>
    </div>

    <!-- Section 5: Worked Example -->
    <div class="method-card mc-teal">
        <div class="section-number">Worked Example</div>
        <h2>Step-by-Step Calculation Sample</h2>
        <p>Given a projected NCR SHS enrollment of <strong>350,000</strong> for the target school year, using current system parameters:</p>

        <?php
        $sample   = 350000;
        $acad_s   = $sample * $academic_ratio;
        $tvl_s    = $sample * $tvl_ratio;
        $acad_r   = ceil($acad_s / $class_size);
        $tvl_r    = ceil($tvl_s / $class_size);
        $acad_t   = ceil($acad_r / $sections_per_teacher);
        $tvl_t    = ceil($tvl_r  / $sections_per_teacher);
        ?>

        <div class="example-box">
            <div class="example-title">📊 Sample Calculation — 350,000 Projected Enrollees</div>
            <div class="example-step">
                <div class="step-num">1</div>
                <div class="step-text">
                    <strong>Split by track:</strong><br>
                    Academic = 350,000 × <?php echo round($academic_ratio*100,0); ?>% = <code><?php echo number_format($acad_s); ?> students</code><br>
                    TVL = 350,000 × <?php echo round($tvl_ratio*100,0); ?>% = <code><?php echo number_format($tvl_s); ?> students</code>
                </div>
            </div>
            <div class="example-step">
                <div class="step-num">2</div>
                <div class="step-text">
                    <strong>Compute classrooms (÷ <?php echo $class_size; ?> students/room):</strong><br>
                    Academic Rooms = <?php echo number_format($acad_s); ?> ÷ <?php echo $class_size; ?> = <code><?php echo number_format($acad_r); ?> rooms</code><br>
                    TVL Rooms = <?php echo number_format($tvl_s); ?> ÷ <?php echo $class_size; ?> = <code><?php echo number_format($tvl_r); ?> rooms</code>
                </div>
            </div>
            <div class="example-step">
                <div class="step-num">3</div>
                <div class="step-text">
                    <strong>Compute teachers (÷ <?php echo $sections_per_teacher; ?> sections/teacher):</strong><br>
                    Academic Teachers = <?php echo number_format($acad_r); ?> ÷ <?php echo $sections_per_teacher; ?> = <code><?php echo number_format($acad_t); ?> teachers</code><br>
                    TVL Teachers = <?php echo number_format($tvl_r); ?> ÷ <?php echo $sections_per_teacher; ?> = <code><?php echo number_format($tvl_t); ?> teachers</code>
                </div>
            </div>
            <div class="example-step">
                <div class="step-num">✓</div>
                <div class="step-text">
                    <strong>Result:</strong>
                    Rooms (<?php echo number_format($acad_r + $tvl_r); ?> total) &gt; Teachers (<?php echo number_format($acad_t + $tvl_t); ?> total) — as expected per DepEd planning norms.
                </div>
            </div>
        </div>
    </div>

    <!-- Section 6: Limitations -->
    <div class="method-card mc-navy" style="border-top: 4px solid var(--red);">
        <div class="section-number">Section 06</div>
        <h2>Known Limitations & Scope Boundaries</h2>
        <p>
            This system is a planning prototype intended to support evidence-based budget discussions.
            The following limitations apply and should be acknowledged in any formal use of its outputs:
        </p>

        <div class="limitation-grid">
            <div class="limit-item">
                <strong>NCR Aggregate Only</strong>
                The NCR Forecast page uses region-wide totals. It does not account for unequal distribution
                of enrollment growth across schools, cities, or divisions within NCR.
            </div>
            <div class="limit-item">
                <strong>Historical Data Quality</strong>
                Forecast accuracy depends entirely on the quality and completeness of the uploaded
                historical dataset. Gaps, outliers, or COVID-era anomalies (2020–2021) may affect
                model outputs.
            </div>
            <div class="limit-item">
                <strong>Fixed Track Split</strong>
                The Academic/TVL ratio is a system-wide constant. In reality, this varies by school,
                barangay, and school year. Per-school uploads partially address this by using each
                school's own historical track ratios.
            </div>
            <div class="limit-item">
                <strong>No Labor Market Integration</strong>
                Teacher and classroom projections do not account for existing vacancies, current
                deployment, retirement rates, or DepEd's own hiring pipeline. These factors must
                be separately assessed by DepEd HR.
            </div>
            <div class="limit-item">
                <strong>Sparse Training Data</strong>
                SHS only began in 2016, giving roughly 9–10 years of data. Prophet performs best
                with longer histories. The 95% confidence intervals widen significantly for later
                forecast years and should be interpreted with caution.
            </div>
            <div class="limit-item">
                <strong>Sports & Arts/Design Tracks Excluded</strong>
                The system only splits Academic vs TVL. Sports and Arts & Design tracks — which
                have different resource requirements (facilities, specialized teachers) — are not
                modeled separately.
            </div>
        </div>
    </div>

</div><!-- end .method-wrapper -->

<?php $bg_mode = 'dashboard'; include 'bg_canvas.php'; ?>
</body>
</html>