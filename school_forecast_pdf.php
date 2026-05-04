<?php
/**
 * school_forecast_pdf.php
 * Generates a print-ready HTML report for the per-school SHS enrollment forecast.
 * Opened in a new tab via window.open() — user prints/saves as PDF from the browser.
 */
session_start();
include 'db.php';

// ── Auth ────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php'); exit;
}
$timeout_duration = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset(); session_destroy();
    header('Location: index.php'); exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

// ── Load data ───────────────────────────────────────────────────────────────
if (!file_exists('forecast_per_school.csv')) {
    die('<p style="font-family:sans-serif;padding:40px;color:#c0202a;">No per-school forecast found. Please generate a forecast first and try again.</p>');
}

$psRows = []; $psYears = [];
$handle = fopen('forecast_per_school.csv', 'r');
$hdr    = array_map('trim', fgetcsv($handle));
while (($row = fgetcsv($handle)) !== false) {
    $row = array_map('trim', $row);
    $combined = array_combine($hdr, $row);
    $combined['Year'] = (int)floatval($combined['Year']);
    $psRows[] = $combined;
}
fclose($handle);
$psYears = array_values(array_unique(array_column($psRows, 'Year')));
sort($psYears);

// Load accuracy
$accuracy = null;
if (file_exists('forecast_accuracy.json')) {
    $accuracy = json_decode(file_get_contents('forecast_accuracy.json'), true);
}

// Load config
$config = file_exists('config.json')
    ? json_decode(file_get_contents('config.json'), true)
    : [];
$class_size           = $config['class_size']           ?? 40;
$academic_ratio       = $config['academic_ratio']        ?? 0.65;
$tvl_ratio            = $config['tvl_ratio']             ?? 0.35;
$sections_per_teacher = $config['sections_per_teacher']  ?? 1.5;

// Compute per-year summary totals
$yearSummaries = [];
foreach ($psYears as $yr) {
    $rows = array_values(array_filter($psRows, fn($r) => $r['Year'] === $yr));
    $yearSummaries[$yr] = [
        'schools'       => count($rows),
        'enrollees'     => array_sum(array_column($rows, 'Projected_Enrollees')),
        'acad_rooms'    => array_sum(array_column($rows, 'Academic_Classrooms')),
        'tvl_rooms'     => array_sum(array_column($rows, 'TVL_Classrooms')),
        'acad_teachers' => array_sum(array_column($rows, 'Academic_Teachers')),
        'tvl_teachers'  => array_sum(array_column($rows, 'TVL_Teachers')),
    ];
}

$generatedAt = date('F j, Y \a\t g:i A');
$generatedBy = htmlspecialchars($_SESSION['username'] ?? 'User');
$totalSchools = count(array_unique(array_column($psRows, 'School_ID')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>NCR SHS Per-School Forecast Report</title>
<style>
  /* ── Print setup ── */
  @page {
    size: A4 landscape;
    margin: 15mm 12mm 18mm 12mm;
  }

  @media print {
    .no-print { display: none !important; }
    .page-break { page-break-before: always; }
    .avoid-break { page-break-inside: avoid; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .report-page { padding-top: 0 !important; }
  }

  /* ── Base ── */
  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 9pt;
    color: #1a1a2e;
    background: #fff;
    line-height: 1.4;
  }

  /* ── Print button (hidden on print) ── */
  .print-bar {
    position: fixed;
    top: 0; left: 0; right: 0;
    background: #0B2A6B;
    color: #fff;
    padding: 10px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    z-index: 999;
    box-shadow: 0 2px 12px rgba(0,0,0,0.3);
  }

  .print-bar .bar-title {
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.3px;
  }

  .print-bar .bar-sub {
    font-size: 11px;
    opacity: 0.7;
    margin-top: 2px;
  }

  .print-btn {
    background: #D4A843;
    color: #0B2A6B;
    border: none;
    padding: 8px 22px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 7px;
    transition: all 0.18s;
    white-space: nowrap;
  }

  .print-btn:hover { background: #e8b84a; transform: translateY(-1px); }

  .close-btn {
    background: rgba(255,255,255,0.12);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.18s;
    text-decoration: none;
  }

  .close-btn:hover { background: rgba(255,255,255,0.2); }

  /* ── Report content ── */
  .report-page {
    max-width: 277mm;
    margin: 0 auto;
    padding: 72px 0 24px; /* top pad = print-bar height */
  }

  /* ── Header ── */
  .report-header {
    background: linear-gradient(135deg, #0B2A6B 0%, #1A4099 100%);
    color: #fff;
    padding: 22px 28px;
    border-radius: 8px 8px 0 0;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 0;
    border-bottom: 4px solid #D4A843;
  }

  .header-left h1 {
    font-size: 15pt;
    font-weight: 800;
    letter-spacing: 0.2px;
    margin-bottom: 4px;
  }

  .header-left p {
    font-size: 9pt;
    opacity: 0.75;
    margin: 2px 0;
    color: #fff;
  }

  .header-right {
    text-align: right;
    white-space: nowrap;
    flex-shrink: 0;
  }

  .header-right .dept {
    font-size: 8pt;
    opacity: 0.65;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 4px;
    color: #fff;
  }

  .header-right .gen-info {
    font-size: 8pt;
    opacity: 0.75;
    line-height: 1.6;
    color: #fff;
  }

  .accuracy-pill {
    display: inline-block;
    background: rgba(212,168,67,0.2);
    border: 1px solid rgba(212,168,67,0.5);
    color: #D4A843;
    font-size: 8.5pt;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    margin-top: 6px;
  }

  /* ── Meta strip ── */
  .meta-strip {
    background: #F0F4FF;
    border: 1px solid #C8D3EE;
    border-top: none;
    padding: 10px 28px;
    display: flex;
    gap: 32px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 16px;
  }

  .meta-item { font-size: 8pt; color: #3D4B6B; }
  .meta-item strong { color: #0B2A6B; font-weight: 700; }

  /* ── Section title ── */
  .section-title {
    font-size: 11pt;
    font-weight: 800;
    color: #0B2A6B;
    padding: 10px 0 6px;
    border-bottom: 2px solid #C8D3EE;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    page-break-after: avoid;
    break-after: avoid;
  }

  /* ── Year summary cards ── */
  .year-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
    margin-bottom: 20px;
  }

  .year-card {
    border: 1px solid #C8D3EE;
    border-radius: 6px;
    padding: 12px 14px;
    border-top: 3px solid #0B2A6B;
    background: #FAFBFF;
  }

  .year-card.yr2 { border-top-color: #157A47; }
  .year-card.yr3 { border-top-color: #D4A843; }
  .year-card.yr4 { border-top-color: #5B21B6; }

  .year-card .yc-year {
    font-size: 10pt;
    font-weight: 800;
    color: #0B2A6B;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px solid #E0E8F5;
  }

  .yc-row {
    display: flex;
    justify-content: space-between;
    font-size: 7.8pt;
    margin-bottom: 3px;
    color: #3D4B6B;
  }

  .yc-row .yc-label { color: #6878A8; }
  .yc-row .yc-val   { font-weight: 700; color: #0B2A6B; }
  .yc-total {
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px solid #E0E8F5;
    display: flex;
    justify-content: space-between;
    font-size: 8.5pt;
    font-weight: 800;
    color: #0B2A6B;
  }

  /* ── School table ── */
  .school-section {
    margin-bottom: 24px;
  }

  .table-wrapper {
    width: 100%;
  }

  .school-year-label {
    font-size: 9.5pt;
    font-weight: 800;
    color: #fff;
    background: linear-gradient(135deg, #0B2A6B, #1A4099);
    padding: 6px 14px;
    border-radius: 5px 5px 0 0;
    display: block;
    margin-bottom: 0;
    width: 100%;
    box-sizing: border-box;
    page-break-after: avoid;
    break-after: avoid;
  }

  table.school-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 7.8pt;
    margin-bottom: 6px;
    border-radius: 0 0 5px 5px;
    overflow: hidden;
  }

  table.school-table thead {
    display: table-header-group; /* repeat header on each printed page */
  }

  table.school-table thead th {
    background: #0B2A6B;
    color: #fff;
    padding: 7px 8px;
    text-align: left;
    font-weight: 700;
    font-size: 7pt;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
    border-bottom: 2px solid #D4A843;
  }

  table.school-table thead th.num { text-align: right; }

  table.school-table tbody tr:nth-child(even) { background: #F5F7FF; }
  table.school-table tbody tr:hover { background: #EEF2FF; }

  table.school-table tbody td {
    padding: 5px 8px;
    border-bottom: 1px solid #E8EDF5;
    color: #1a1a2e;
    vertical-align: middle;
  }

  table.school-table tbody td.num {
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  table.school-table tbody td.ci {
    text-align: right;
    color: #6878A8;
    font-size: 7pt;
  }

  table.school-table tfoot td {
    background: #E8EDF5;
    font-weight: 800;
    color: #0B2A6B;
    padding: 6px 8px;
    border-top: 2px solid #0B2A6B;
    font-size: 8pt;
  }

  table.school-table tfoot td.num {
    text-align: right;
  }

  /* ── Disclaimer ── */
  .disclaimer {
    background: #FFFBEB;
    border: 1px solid #FDE68A;
    border-left: 4px solid #B45309;
    border-radius: 5px;
    padding: 10px 14px;
    font-size: 7.5pt;
    color: #78350F;
    margin-top: 16px;
    line-height: 1.6;
  }

  /* ── Footer ── */
  .report-footer {
    margin-top: 20px;
    padding-top: 10px;
    border-top: 1px solid #C8D3EE;
    display: flex;
    justify-content: space-between;
    font-size: 7.5pt;
    color: #6878A8;
  }

  /* ── Formula strip ── */
  .formula-strip {
    background: #F0F4FF;
    border: 1px solid #C8D3EE;
    border-radius: 5px;
    padding: 8px 14px;
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 20px;
    font-size: 7.5pt;
    color: #3D4B6B;
  }

  .formula-strip .fs-item { white-space: nowrap; }
  .formula-strip .fs-item strong { color: #0B2A6B; }
</style>
</head>
<body>

<!-- ── Print action bar (hidden when printing) ── -->
<div class="print-bar no-print">
    <div>
        <div class="bar-title">📄 NCR SHS Per-School Forecast Report</div>
        <div class="bar-sub">Click "Print / Save as PDF" → choose "Save as PDF" in your browser's print dialog</div>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
        <a href="school_forecast.php" class="close-btn">✕ Close</a>
    </div>
</div>

<!-- ── Report content ── -->
<div class="report-page">

    <!-- Header -->
    <div class="report-header avoid-break">
        <div class="header-left">
            <h1>NCR Senior High School Enrollment Forecast</h1>
            <p>Per-School Resource Allocation Report</p>
            <p>Department of Education — National Capital Region</p>
            <?php if ($accuracy): ?>
            <div class="accuracy-pill">
                Model Accuracy: <?php echo $accuracy['accuracy']; ?>% &nbsp;·&nbsp;
                MAPE: <?php echo $accuracy['mape']; ?>%
            </div>
            <?php endif; ?>
        </div>
        <div class="header-right">
            <div class="dept">DepEd NCR Planning Division</div>
            <div class="gen-info">
                Generated: <?php echo $generatedAt; ?><br>
                Prepared by: <?php echo $generatedBy; ?><br>
                Forecast Horizon: SY <?php echo min($psYears); ?>–<?php echo max($psYears); ?><br>
                Schools covered: <?php echo $totalSchools; ?>
            </div>
        </div>
    </div>

    <!-- Meta strip -->
    <div class="meta-strip avoid-break">
        <div class="meta-item">Class size: <strong><?php echo $class_size; ?> students/room</strong></div>
        <div class="meta-item">Academic ratio: <strong><?php echo round($academic_ratio*100,1); ?>%</strong></div>
        <div class="meta-item">TVL ratio: <strong><?php echo round($tvl_ratio*100,1); ?>%</strong></div>
        <div class="meta-item">Sections per teacher: <strong><?php echo $sections_per_teacher; ?></strong></div>
        <div class="meta-item">Confidence level: <strong>95%</strong></div>
        <div class="meta-item">Model: <strong>Facebook Prophet</strong></div>
    </div>

    <!-- ── Section 1: Executive Summary by Year ── -->
    <div class="section-title">📊 Executive Summary — NCR Totals by Forecast Year</div>
    <div class="year-summary-grid avoid-break">
        <?php
        $colorClasses = ['', 'yr2', 'yr3', 'yr4'];
        $ci = 0;
        foreach ($psYears as $yr):
            $s = $yearSummaries[$yr];
        ?>
        <div class="year-card <?php echo $colorClasses[$ci % 4]; ?>">
            <div class="yc-year">School Year <?php echo $yr; ?></div>
            <div class="yc-row">
                <span class="yc-label">Schools</span>
                <span class="yc-val"><?php echo number_format($s['schools']); ?></span>
            </div>
            <div class="yc-row">
                <span class="yc-label">Projected Enrollees</span>
                <span class="yc-val"><?php echo number_format($s['enrollees']); ?></span>
            </div>
            <div class="yc-row">
                <span class="yc-label">Academic Classrooms</span>
                <span class="yc-val"><?php echo number_format($s['acad_rooms']); ?></span>
            </div>
            <div class="yc-row">
                <span class="yc-label">TVL Classrooms</span>
                <span class="yc-val"><?php echo number_format($s['tvl_rooms']); ?></span>
            </div>
            <div class="yc-total">
                <span>Total Classrooms</span>
                <span><?php echo number_format($s['acad_rooms'] + $s['tvl_rooms']); ?></span>
            </div>
            <div class="yc-row" style="margin-top:4px;">
                <span class="yc-label">Academic Teachers</span>
                <span class="yc-val"><?php echo number_format($s['acad_teachers']); ?></span>
            </div>
            <div class="yc-row">
                <span class="yc-label">TVL Teachers</span>
                <span class="yc-val"><?php echo number_format($s['tvl_teachers']); ?></span>
            </div>
            <div class="yc-total">
                <span>Total Teachers</span>
                <span><?php echo number_format($s['acad_teachers'] + $s['tvl_teachers']); ?></span>
            </div>
        </div>
        <?php $ci++; endforeach; ?>
    </div>

    <!-- ── Section 2: Per-School Detail Tables (one per year) ── -->
    <?php foreach ($psYears as $yIdx => $yr):
        $rows = array_values(array_filter($psRows, fn($r) => $r['Year'] === $yr));
        usort($rows, fn($a, $b) => strcmp($a['School_Name'], $b['School_Name']));
        $s = $yearSummaries[$yr];
    ?>
    <div class="school-section page-break">
        <div class="section-title">
            🏫 School-Level Resource Breakdown — School Year <?php echo $yr; ?>
        </div>
        <div class="table-wrapper">
        <div class="school-year-label">SY <?php echo $yr; ?> — <?php echo count($rows); ?> schools</div>
        <table class="school-table">
            <thead>
                <tr>
                    <th style="width:30%;">School</th>
                    <th style="width:14%;">City</th>
                    <th class="num" style="width:11%;">Projected<br>Enrollees</th>
                    <th class="num" style="width:13%;">95% CI Range</th>
                    <th class="num" style="width:8%;">Acad.<br>Rooms</th>
                    <th class="num" style="width:8%;">TVL<br>Rooms</th>
                    <th class="num" style="width:8%;">Acad.<br>Teachers</th>
                    <th class="num" style="width:8%;">TVL<br>Teachers</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo htmlspecialchars($r['School_Name']); ?></td>
                    <td><?php echo htmlspecialchars($r['City'] ?? ''); ?></td>
                    <td class="num"><strong><?php echo number_format($r['Projected_Enrollees']); ?></strong></td>
                    <td class="ci"><?php echo number_format($r['Lower_Bound']); ?>–<?php echo number_format($r['Upper_Bound']); ?></td>
                    <td class="num"><?php echo number_format($r['Academic_Classrooms']); ?></td>
                    <td class="num"><?php echo number_format($r['TVL_Classrooms']); ?></td>
                    <td class="num"><?php echo number_format($r['Academic_Teachers']); ?></td>
                    <td class="num"><?php echo number_format($r['TVL_Teachers']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2"><strong>NCR TOTAL — SY <?php echo $yr; ?></strong></td>
                    <td class="num"><?php echo number_format($s['enrollees']); ?></td>
                    <td class="num" style="color:#6878A8; font-size:7pt;">—</td>
                    <td class="num"><?php echo number_format($s['acad_rooms']); ?></td>
                    <td class="num"><?php echo number_format($s['tvl_rooms']); ?></td>
                    <td class="num"><?php echo number_format($s['acad_teachers']); ?></td>
                    <td class="num"><?php echo number_format($s['tvl_teachers']); ?></td>
                </tr>
            </tfoot>
        </table>
        </div><!-- /.table-wrapper -->
    </div><!-- /.school-section -->
    <?php endforeach; ?>

    <!-- ── Methodology strip ── -->
    <div class="section-title" style="margin-top:10px;">📐 Calculation Parameters</div>
    <div class="formula-strip avoid-break">
        <div class="fs-item"><strong>Academic Rooms</strong> = (Enrollees × <?php echo round($academic_ratio*100,1); ?>%) ÷ <?php echo $class_size; ?></div>
        <div class="fs-item"><strong>TVL Rooms</strong> = (Enrollees × <?php echo round($tvl_ratio*100,1); ?>%) ÷ <?php echo $class_size; ?></div>
        <div class="fs-item"><strong>Academic Teachers</strong> = Academic Rooms ÷ <?php echo $sections_per_teacher; ?></div>
        <div class="fs-item"><strong>TVL Teachers</strong> = TVL Rooms ÷ <?php echo $sections_per_teacher; ?></div>
        <div class="fs-item">Source: DepEd Order No. 21, s. 2006 · RA 4670</div>
    </div>

    <!-- ── Disclaimer ── -->
    <div class="disclaimer avoid-break">
        <strong>⚠️ Disclaimer:</strong> All projections in this report are <strong>planning estimates only</strong>,
        generated by a Facebook Prophet time-series model trained on historical NCR SHS enrollment data.
        Figures are not official DepEd allocations. Actual resource needs depend on per-school assessments,
        infrastructure conditions, and official DepEd procurement decisions. The 95% confidence interval
        represents the statistical uncertainty range — actual enrollment may fall within this range.
        Generated on <?php echo $generatedAt; ?> for planning reference purposes only.
    </div>

    <!-- ── Footer ── -->
    <div class="report-footer">
        <div>NCR SHS Enrollment Forecast System · DepEd National Capital Region</div>
        <div>Generated: <?php echo $generatedAt; ?> · User: <?php echo $generatedBy; ?></div>
    </div>

</div>

<!-- Auto-prompt print dialog on load -->
<script>
window.addEventListener('load', function() {
    // Small delay to let styles render fully before any print dialog
    // (user can also click the button manually)
});
</script>
</body>
</html>