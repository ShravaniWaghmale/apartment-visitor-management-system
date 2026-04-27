<?php
require_once '../../config/db.php';
requireLogin();
requireRole('admin','receptionist');
$user = currentUser();
$db   = getDB();
$s    = getSettings();

// ── Date range filter ─────────────────────────────────────────
$from  = $_GET['from'] ?? date('Y-m-01');
$to    = $_GET['to']   ?? date('Y-m-d');

// ── All analytics pulled live from DB ─────────────────────────

// Summary KPIs
$kpi = $db->prepare("
    SELECT
        COUNT(*) AS total_visits,
        COUNT(*) FILTER(WHERE status='inside') AS inside_now,
        COUNT(*) FILTER(WHERE check_in::date=CURRENT_DATE) AS today_visits,
        COUNT(*) FILTER(WHERE overstay_flagged=TRUE) AS overstay_count,
        ROUND(AVG(EXTRACT(EPOCH FROM (COALESCE(check_out,NOW())-check_in))/3600)::numeric,1) AS avg_hours,
        COUNT(DISTINCT visitor_id) AS unique_visitors
    FROM visits
    WHERE check_in::date BETWEEN :from AND :to
");
$kpi->execute([':from'=>$from,':to'=>$to]);
$kpi = $kpi->fetch();

// Daily trend — fills gaps with 0 using generate_series
$daily = $db->prepare("
    SELECT TO_CHAR(d,'DD Mon') AS label, COALESCE(c.cnt,0) AS cnt
    FROM generate_series(:from::date, :to::date, '1 day') d
    LEFT JOIN (
        SELECT check_in::date AS dt, COUNT(*) AS cnt FROM visits GROUP BY dt
    ) c ON c.dt = d::date
    ORDER BY d
");
$daily->execute([':from'=>$from,':to'=>$to]);
$daily = $daily->fetchAll();

// Hourly distribution — all 24 hours
$hourly = $db->prepare("
    SELECT h.hr, COALESCE(c.cnt,0) AS cnt
    FROM generate_series(0,23) h(hr)
    LEFT JOIN (
        SELECT EXTRACT(HOUR FROM check_in)::int AS hr, COUNT(*) AS cnt
        FROM visits WHERE check_in::date BETWEEN :from AND :to GROUP BY hr
    ) c ON c.hr=h.hr
    ORDER BY h.hr
");
$hourly->execute([':from'=>$from,':to'=>$to]);
$hourly = $hourly->fetchAll();

// Purpose distribution — dynamic from actual visit data
$purposes = $db->prepare("
    SELECT vi.purpose, COUNT(*) AS cnt
    FROM visits v JOIN visitors vi ON v.visitor_id=vi.id
    WHERE v.check_in::date BETWEEN :from AND :to AND vi.purpose IS NOT NULL
    GROUP BY vi.purpose ORDER BY cnt DESC LIMIT 10
");
$purposes->execute([':from'=>$from,':to'=>$to]);
$purposes = $purposes->fetchAll();

// Day-of-week breakdown
$dowData = $db->prepare("
    SELECT TO_CHAR(check_in,'Dy') AS dow,
           EXTRACT(DOW FROM check_in)::int AS dow_num,
           COUNT(*) AS cnt
    FROM visits WHERE check_in::date BETWEEN :from AND :to
    GROUP BY dow, dow_num ORDER BY dow_num
");
$dowData->execute([':from'=>$from,':to'=>$to]);
$dowData = $dowData->fetchAll();

// Top residents by visitor count
$topHosts = $db->prepare("
    SELECT u.name, u.flat_number, COUNT(*) AS cnt
    FROM visits v JOIN users u ON v.host_id=u.id
    WHERE v.check_in::date BETWEEN :from AND :to
    GROUP BY u.name, u.flat_number ORDER BY cnt DESC LIMIT 10
");
$topHosts->execute([':from'=>$from,':to'=>$to]);
$topHosts = $topHosts->fetchAll();
$maxHostCnt = $topHosts ? max(array_column($topHosts,'cnt')) : 1;

// Frequent visitors
$freqVisitors = $db->prepare("
    SELECT vi.full_name, vi.phone, COUNT(*) AS visits_count, MAX(v.check_in) AS last_visit
    FROM visits v JOIN visitors vi ON v.visitor_id=vi.id
    WHERE v.check_in::date BETWEEN :from AND :to
    GROUP BY vi.full_name, vi.phone ORDER BY visits_count DESC LIMIT 8
");
$freqVisitors->execute([':from'=>$from,':to'=>$to]);
$freqVisitors = $freqVisitors->fetchAll();

// Monthly trend last 12 months
$monthly = $db->query("
    SELECT TO_CHAR(DATE_TRUNC('month',check_in),'Mon YY') AS label, COUNT(*) AS cnt
    FROM visits WHERE check_in >= NOW()-INTERVAL '11 months'
    GROUP BY DATE_TRUNC('month',check_in) ORDER BY DATE_TRUNC('month',check_in)
")->fetchAll();

// Overstay rate
$overstayRate = $kpi['total_visits'] > 0
    ? round($kpi['overstay_count'] / $kpi['total_visits'] * 100, 1) : 0;

pageHead('Analytics', '<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>');
renderSidebar('analytics');
?>
<main class="main-content">
  <header class="page-header">
    <div>
      <h1 class="page-title">Analytics & Reports</h1>
      <p class="page-sub">Live data from <?= date('d M Y',strtotime($from)) ?> to <?= date('d M Y',strtotime($to)) ?></p>
    </div>
    <div class="header-actions">
      <!-- Dynamic date range filter -->
      <form method="GET" style="display:flex;gap:8px;align-items:center;">
        <input type="date" name="from" value="<?= $from ?>" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:.82rem;outline:none;">
        <span style="color:var(--muted);">to</span>
        <input type="date" name="to" value="<?= $to ?>" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-size:.82rem;outline:none;">
        <button type="submit" class="btn-secondary">Apply</button>
      </form>
      <a href="<?= rootPath() ?>modules/reports/export.php" class="btn-primary">⬇️ Export</a>
    </div>
  </header>

  <!-- KPI Cards — all from DB -->
  <div class="stats-grid" style="margin-bottom:22px;">
    <div class="stat-card stat-blue">
      <div class="stat-icon">👥</div>
      <div class="stat-num"><?= number_format($kpi['total_visits']) ?></div>
      <div class="stat-label">Total Visits</div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-icon">🔄</div>
      <div class="stat-num"><?= number_format($kpi['unique_visitors']) ?></div>
      <div class="stat-label">Unique Visitors</div>
    </div>
    <div class="stat-card stat-amber">
      <div class="stat-icon">⏱️</div>
      <div class="stat-num"><?= $kpi['avg_hours'] ?>h</div>
      <div class="stat-label">Avg Visit Duration</div>
    </div>
    <div class="stat-card stat-red">
      <div class="stat-icon">⚠️</div>
      <div class="stat-num"><?= $kpi['overstay_count'] ?> <small style="font-size:.8rem;">(<?= $overstayRate ?>%)</small></div>
      <div class="stat-label">Overstay Incidents</div>
    </div>
    <div class="stat-card stat-purple">
      <div class="stat-icon">📅</div>
      <div class="stat-num"><?= $kpi['today_visits'] ?></div>
      <div class="stat-label">Today's Visits</div>
    </div>
    <div class="stat-card stat-dark">
      <div class="stat-icon">🏠</div>
      <div class="stat-num"><?= $kpi['inside_now'] ?></div>
      <div class="stat-label">Inside Right Now</div>
    </div>
  </div>

  <!-- Charts Row 1 -->
  <div class="two-col" style="margin-bottom:20px;">
    <div class="card">
      <div class="card-header"><h3>Daily Visitor Trend</h3><span class="badge badge-muted"><?= count($daily) ?> days</span></div>
      <canvas id="dailyChart" height="220"></canvas>
    </div>
    <div class="card">
      <div class="card-header"><h3>Visit Purposes</h3></div>
      <?php if (!empty($purposes)): ?>
      <canvas id="purposeChart" height="220"></canvas>
      <?php else: ?><div style="text-align:center;padding:50px;color:var(--muted);">No data in this range</div><?php endif; ?>
    </div>
  </div>

  <!-- Charts Row 2 -->
  <div class="two-col" style="margin-bottom:20px;">
    <div class="card">
      <div class="card-header"><h3>Peak Entry Hours</h3><span class="badge badge-muted">00:00 – 23:00</span></div>
      <canvas id="hourlyChart" height="220"></canvas>
    </div>
    <div class="card">
      <div class="card-header"><h3>Monthly Trend (12 months)</h3></div>
      <canvas id="monthlyChart" height="220"></canvas>
    </div>
  </div>

  <!-- Tables Row -->
  <div class="two-col">
    <div class="card">
      <div class="card-header"><h3>🏆 Top Residents by Visitors</h3></div>
      <?php if (empty($topHosts)): ?>
      <p style="text-align:center;color:var(--muted);padding:30px;">No data</p>
      <?php else: foreach ($topHosts as $h): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border);">
        <div class="avatar-placeholder sm"><?= strtoupper(substr($h['name'],0,1)) ?></div>
        <div style="flex:1;">
          <div style="font-size:.86rem;font-weight:500;"><?= htmlspecialchars($h['name']) ?></div>
          <div style="font-size:.73rem;color:var(--muted);">Flat <?= htmlspecialchars($h['flat_number']) ?></div>
        </div>
        <div style="width:80px;">
          <div style="height:5px;background:var(--surface2);border-radius:3px;">
            <div style="width:<?= round($h['cnt']/$maxHostCnt*100) ?>%;height:100%;background:var(--accent);border-radius:3px;"></div>
          </div>
          <div style="font-size:.72rem;color:var(--muted);margin-top:2px;text-align:right;"><?= $h['cnt'] ?> visits</div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><h3>🔄 Frequent Visitors</h3></div>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Name</th><th>Phone</th><th>Visits</th><th>Last Seen</th></tr></thead>
          <tbody>
            <?php if (empty($freqVisitors)): ?>
            <tr><td colspan="4" class="empty-cell">No data</td></tr>
            <?php else: foreach ($freqVisitors as $fv): ?>
            <tr>
              <td><?= htmlspecialchars($fv['full_name']) ?></td>
              <td style="color:var(--muted);"><?= htmlspecialchars($fv['phone'] ?? '—') ?></td>
              <td><span class="badge badge-success"><?= $fv['visits_count'] ?></span></td>
              <td style="font-size:.75rem;color:var(--muted);"><?= date('d M Y',strtotime($fv['last_visit'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<script>
const chartOpts = {
  responsive:true,
  plugins:{legend:{display:false}},
  scales:{
    x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#7a8099',font:{size:10}}},
    y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#7a8099'},beginAtZero:true}
  }
};

// Daily trend
new Chart(document.getElementById('dailyChart'),{
  type:'line',
  data:{
    labels:<?= json_encode(array_column($daily,'label')) ?>,
    datasets:[{
      label:'Visitors',
      data:<?= json_encode(array_column($daily,'cnt')) ?>,
      borderColor:'#e8a838',backgroundColor:'rgba(232,168,56,.1)',
      tension:.4,fill:true,pointRadius:3,pointBackgroundColor:'#e8a838'
    }]
  },
  options:{...chartOpts,plugins:{legend:{display:false}}}
});

<?php if (!empty($purposes)): ?>
// Purpose doughnut
new Chart(document.getElementById('purposeChart'),{
  type:'doughnut',
  data:{
    labels:<?= json_encode(array_column($purposes,'purpose')) ?>,
    datasets:[{
      data:<?= json_encode(array_column($purposes,'cnt')) ?>,
      backgroundColor:['#e8a838','#3ecf8e','#5b9cf6','#a78bfa','#f87171','#34d399','#fbbf24','#60a5fa','#fb7185','#a3e635'],
      borderWidth:0
    }]
  },
  options:{responsive:true,plugins:{legend:{position:'right',labels:{color:'#7a8099',font:{size:11}}}}}
});
<?php endif; ?>

// Hourly
new Chart(document.getElementById('hourlyChart'),{
  type:'bar',
  data:{
    labels:<?= json_encode(array_map(fn($h)=>str_pad($h['hr'],2,'0',STR_PAD_LEFT).':00',$hourly)) ?>,
    datasets:[{
      data:<?= json_encode(array_column($hourly,'cnt')) ?>,
      backgroundColor:'rgba(91,156,246,.7)',borderRadius:4
    }]
  },
  options:chartOpts
});

// Monthly
new Chart(document.getElementById('monthlyChart'),{
  type:'bar',
  data:{
    labels:<?= json_encode(array_column($monthly,'label')) ?>,
    datasets:[{
      data:<?= json_encode(array_column($monthly,'cnt')) ?>,
      backgroundColor:'rgba(167,139,250,.7)',borderRadius:6
    }]
  },
  options:chartOpts
});
</script>
</body>
</html>
