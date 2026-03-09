<?php
declare(strict_types=1);
require __DIR__ . '/../../inc/bootstrap.php';
require __DIR__ . '/../_ui.php';
require_admin();
require_permission('membership.view');
ensure_membership_applications_table();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_verify();

  if (isset($_POST['toggle_called_id'])) {
    $id = (int)($_POST['toggle_called_id'] ?? 0);
    if ($id > 0) {
      $stmt = db()->prepare('UPDATE membership_applications SET is_called = CASE WHEN is_called = 1 THEN 0 ELSE 1 END WHERE id = ? LIMIT 1');
      $stmt->execute([$id]);
    }
  }

  if (isset($_POST['delete_application_id'])) {
    $id = (int)($_POST['delete_application_id'] ?? 0);
    if ($id > 0) {
      $stmt = db()->prepare('DELETE FROM membership_applications WHERE id = ? LIMIT 1');
      $stmt->execute([$id]);
    }
  }

  header('Location: ' . url('admin/memberships/index.php'));
  exit;
}

try {
  $rows = db()->query('SELECT id, full_name, phone, email, university_info, age, legal_address, desired_direction, motivation_text, is_called, created_at FROM membership_applications ORDER BY created_at DESC, id DESC')->fetchAll();
} catch (Throwable $e) {
  $legacyRows = db()->query('SELECT id, first_name, last_name, personal_id, phone, university, faculty, email, additional_info, created_at FROM membership_applications ORDER BY created_at DESC, id DESC')->fetchAll();
  $rows = [];
  foreach ($legacyRows as $r) {
    $rows[] = [
      'id' => $r['id'],
      'full_name' => $r['first_name'] ?? '',
      'phone' => $r['phone'] ?? '',
      'email' => $r['email'] ?? '',
      'university_info' => $r['university'] ?? '',
      'age' => $r['last_name'] ?? '',
      'legal_address' => $r['personal_id'] ?? '',
      'desired_direction' => $r['faculty'] ?? '',
      'motivation_text' => $r['additional_info'] ?? '',
      'is_called' => 0,
      'created_at' => $r['created_at'] ?? '',
    ];
  }
}
?>
<!doctype html>
<html lang="en">
<?php admin_head('Admin — Membership Applications'); ?>
<body class="admin-body">
  <div class="admin-wrap">
    <?php admin_topbar('Membership Applications', [
      ['href' => url('admin/news/index.php'), 'label' => 'News Admin'],
      ['href' => url('admin/logout.php'), 'label' => 'Logout'],
    ]); ?>

    <style>
      .apps-list{display:grid;gap:12px;margin-top:14px}
      .app-card{background:linear-gradient(180deg,#101a2d,#0b1424);border:1px solid rgba(148,163,184,.24);border-radius:14px;padding:14px;transition:filter .15s ease,opacity .15s ease}
      .app-card.is-called{filter:blur(1.5px) saturate(.6);opacity:.72}
      .app-head{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
      .app-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
      .called-btn,.delete-btn{border:1px solid rgba(147,197,253,.45);background:rgba(30,64,175,.28);color:#e2e8f0;padding:7px 10px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer}
      .called-btn.is-called{background:rgba(34,197,94,.25);border-color:rgba(34,197,94,.5);color:#dcfce7}
      .delete-btn{background:rgba(220,38,38,.26);border-color:rgba(248,113,113,.55);color:#fee2e2}
      .app-id{font-weight:900;color:#bfdbfe}
      .app-date{font-size:12px;color:#9fb2cc}
      .app-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
      .app-item{background:rgba(11,20,36,.55);border:1px solid rgba(148,163,184,.16);border-radius:10px;padding:9px}
      .app-item b{display:block;color:#9fb2cc;font-size:12px;margin-bottom:3px}
      .app-item span{color:#e6edf7;font-size:13px;line-height:1.45;word-break:break-word}
      .app-motivation{margin-top:10px;background:rgba(14,165,233,.07);border:1px solid rgba(14,165,233,.25);border-radius:10px;padding:10px}
      .app-motivation b{display:block;color:#93c5fd;font-size:12px;margin-bottom:4px}
      .app-motivation .text{white-space:pre-line;word-break:break-word;overflow-wrap:anywhere;line-height:1.55;font-size:13px;color:#e2e8f0;max-height:160px;overflow:auto}
      @media (max-width:900px){.app-grid{grid-template-columns:1fr}}
    </style>

    <div class="grid-3">
      <div class="admin-card"><label>Total</label><div style="font-size:30px;font-weight:900"><?= count($rows) ?></div></div>
      <?php $todayCount = 0; foreach($rows as $r){ if (str_starts_with((string)$r['created_at'], date('Y-m-d'))) $todayCount++; } ?>
      <div class="admin-card"><label>Today</label><div style="font-size:30px;font-weight:900;color:#93c5fd"><?= $todayCount ?></div></div>
      <div class="admin-card"><label>Latest</label><div style="font-size:15px;font-weight:700;color:#cbd5e1"><?= $rows ? h((string)$rows[0]['created_at']) : '—' ?></div></div>
    </div>

    <?php if(!$rows): ?>
      <div class="admin-card" style="margin-top:14px">No membership applications yet.</div>
    <?php else: ?>
      <div class="apps-list">
        <?php foreach($rows as $r): ?>
          <article class="app-card <?= !empty($r['is_called']) ? 'is-called' : '' ?>">
            <div class="app-head">
              <div class="app-id">Application #<?= (int)$r['id'] ?></div>
              <div class="app-actions">
                <div class="app-date"><?= h((string)$r['created_at']) ?></div>
                <form method="post" style="margin:0">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                  <input type="hidden" name="toggle_called_id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="called-btn <?= !empty($r['is_called']) ? 'is-called' : '' ?>">
                    <?= !empty($r['is_called']) ? 'Called ✓ (click to unblur)' : 'Called' ?>
                  </button>
                </form>
                <form method="post" style="margin:0" onsubmit="return confirm('Delete this membership application?');">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                  <input type="hidden" name="delete_application_id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="delete-btn">Delete</button>
                </form>
              </div>
            </div>

            <div class="app-grid">
              <div class="app-item"><b>სახელი გვარი</b><span><?= h((string)($r['full_name'] ?? '')) ?></span></div>
              <div class="app-item"><b>ტელეფონის ნომერი</b><span><?= h((string)($r['phone'] ?? '')) ?></span></div>
              <div class="app-item"><b>ელ. ფოსტის მისამართი</b><span><?= h((string)($r['email'] ?? '')) ?></span></div>
              <div class="app-item"><b>უნივერსიტეტი, ფაკულტეტი, კურსი</b><span><?= h((string)($r['university_info'] ?? '')) ?></span></div>
              <div class="app-item"><b>ასაკი</b><span><?= h((string)($r['age'] ?? '')) ?></span></div>
              <div class="app-item"><b>იურიდიული მისამართი</b><span><?= h((string)($r['legal_address'] ?? '')) ?></span></div>
              <div class="app-item" style="grid-column:1/-1"><b>სასურველი მიმართულება</b><span><?= h((string)($r['desired_direction'] ?? '')) ?></span></div>
            </div>

            <div class="app-motivation">
              <b>მოტივაცია</b>
              <div class="text"><?= h((string)($r['motivation_text'] ?? '')) ?></div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
