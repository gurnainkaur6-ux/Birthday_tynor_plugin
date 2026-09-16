<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rbac.php';

// Content page: any signed-in user with the Channels feature may view; only
// administrators may change them (mutations gated by settings.manage below).
require_feature($conn, 'channels');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $message = 'Invalid security token. Please refresh and try again.';
        $messageType = 'error';
    } else {
        require_cap('settings.manage');
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
        $chName = trim($_POST['channel_name'] ?? '');
        $chKey  = strtolower(trim($_POST['channel_key'] ?? ''));
        if (!$chName || !$chKey) {
            $message = 'Channel name and key are required.';
            $messageType = 'error';
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO notification_channels (channel_name, channel_key, is_active)
                 VALUES (:name, :key, 1)'
            );
            $stmt->execute(['name' => $chName, 'key' => $chKey]);
        }
        $message = 'Channel added.';
        $messageType = 'success';

    } elseif ($action === 'toggle' && !empty($_POST['id'])) {
        $stmt = $conn->prepare('UPDATE notification_channels SET is_active = 1 - is_active WHERE id = :id');
        $stmt->execute(['id' => (int)$_POST['id']]);
        $message = 'Channel status updated.';
        $messageType = 'success';

    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        $stmt = $conn->prepare('DELETE FROM notification_channels WHERE id = :id');
        $stmt->execute(['id' => (int)$_POST['id']]);
        $message = 'Channel deleted.';
        }
    }
}

try {
    $channels = $conn->query('SELECT * FROM notification_channels ORDER BY channel_name')->fetchAll();
} catch (PDOException $e) {
    $channels = [];
    $message = 'notification_channels table missing — please run setup.php first.';
    $messageType = 'error';
}

// Only administrators may change channels; everyone granted the Channels feature
// may VIEW their status. Controls degrade gracefully for view-only users.
$canManage = user_can('settings.manage');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Channels | Birthday Notification System</title>
    <link rel="stylesheet" href="<?= ASSETS_URL ?>/css/styles.css">
</head>
<body class="dashboard-page">

    <?php
      $NAV_ACTIVE = 'channels';
      $BREADCRUMBS = [['label' => 'Dashboard', 'url' => BASE_URL . '/dashboard/index.php'], ['label' => 'Channels']];
      require __DIR__ . '/../includes/topbar.php';
    ?>

    <main id="main-content" class="dashboard-content">
        <div class="page-header">
            <h1>Notification Channels</h1>
            <?php if ($canManage): ?>
            <button class="btn-primary" onclick="document.getElementById('add-form').classList.toggle('hidden')" id="btn-add-channel">
                + Add Channel
            </button>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- Add Channel Form -->
        <?php if ($canManage): ?>
        <div id="add-form" class="panel hidden">
            <h2>New Notification Channel</h2>
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="channel_name">Channel Name</label>
                        <input type="text" id="channel_name" name="channel_name" required placeholder="Email Delivery">
                    </div>
                    <div class="form-group">
                        <label for="channel_key">Channel Key</label>
                        <input type="text" id="channel_key" name="channel_key" required placeholder="email"
                               pattern="[a-z0-9_]+" title="Lowercase letters, numbers, underscores only">
                    </div>
                </div>
                <div style="margin-top:1rem;display:flex;gap:.75rem;">
                    <button type="submit" class="btn-primary">Save Channel</button>
                    <button type="button" class="btn-secondary" onclick="document.getElementById('add-form').classList.add('hidden')">Cancel</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Channels Table -->
        <div class="panel">
            <?php if (empty($channels)): ?>
                <p class="empty-state">No channels configured. Add one to enable notifications.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table" id="channels-table">
                        <thead>
                            <tr>
                                <th>Channel Name</th>
                                <th>Key</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($channels as $ch): ?>
                                <tr>
                                    <td><?= htmlspecialchars($ch['channel_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><code><?= htmlspecialchars($ch['channel_key'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td>
                                        <span class="badge <?= $ch['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                            <?= $ch['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td style="display:flex;gap:.5rem;">
                                        <?php if ($canManage): ?>
                                        <form method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                                            <button type="submit" class="btn-secondary btn-sm">
                                                <?= $ch['is_active'] ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Delete this channel?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
                                            <button type="submit" class="btn-danger btn-sm">Delete</button>
                                        </form>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:.8rem;">View only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
<script src="<?= ASSETS_URL ?>/js/vendor/lucide.min.js"></script>
<script src="<?= ASSETS_URL ?>/js/ui.js"></script>
</body>
</html>