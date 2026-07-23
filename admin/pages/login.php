<?php
/**
 * OURCR ONLINE - Admin Panel Login
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

// Redirect if already logged in as admin
if (isLoggedIn() && hasRole('admin', 'superadmin')) {
    redirectTo('admin/dashboard');
}

$error = '';

if (isPost()) {
    try {
        requireCsrf();

        $identity = sanitizeString(post('identity')); // username or email
        $password = post('password');

        if (empty($identity) || empty($password)) {
            setFlash('error', "Please enter both credentials.");
            redirectTo('admin/login');
        } else {
            // Attempt login via Authentication Class or inline
            $user = Database::fetchOne(
                "SELECT * FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL LIMIT 1",
                [$identity, $identity]
            );

            if ($user && verifyPassword($password, $user['password_hash'])) {
                // Check if suspended
                if ($user['status'] === 'suspended') {
                    setFlash('error', "Your account is temporarily suspended.");
                    redirectTo('admin/login');
                } elseif ($user['status'] === 'banned') {
                    setFlash('error', "Your account is permanently banned.");
                    redirectTo('admin/login');
                } elseif (!in_array($user['role'], ['admin', 'superadmin'])) {
                    setFlash('error', "Access Forbidden: Admin privileges required.");
                    redirectTo('admin/login');
                } else {
                    // Start session
                    loginUser($user);
                    auditLog('ADMIN_LOGIN', "Logged into admin panel from " . getClientIP(), 'users', $user['id']);
                    
                    setFlash('success', 'Welcome back to admin command panel.');
                    redirectTo('admin/dashboard');
                }
            } else {
                setFlash('error', "Invalid credential combinations.");
                redirectTo('admin/login');
            }
        }
    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Admin login exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine()
        ]);
        setFlash('error', 'A technical error occurred.');
        redirectTo('admin/login');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — OURCR ONLINE</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/master_style.css?v=<?= APP_VERSION ?>">
</head>
<body class="admin-body bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">

    <div class="card border-0 shadow-lg p-4 rounded-16" style="width: 100%; max-width: 400px; background-color: #ffffff;">
        <div class="text-center mb-4">
            <h4 class="fw-bold mb-1 text-primary"><i class="fas fa-shield-halved me-2"></i>OURCR ADMIN</h4>
            <p class="text-muted small">Administrative Command Center Portal</p>
        </div>

        <?php renderSweetAlerts(); ?>

        <form method="POST" action="<?= APP_URL ?>/admin/login">
            <?= csrfField() ?>

            <div class="mb-3">
                <label for="identity" class="form-label small">Email or Username</label>
                <input type="text" class="form-control" id="identity" name="identity" required autofocus>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label small">Security Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-3 rounded-12 fw-bold" style="background-color: #4f46e5; border: none;">
                Login to Console <i class="fas fa-sign-in-alt ms-2"></i>
            </button>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <?php renderSweetAlerts(); ?>
</body>
</html>
