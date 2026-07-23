<?php
/**
 * OURCR ONLINE - User Profile & Customisation
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');
requireAuth();

$pageTitle = 'My Profile';
$user = currentUser();
$siteColor = $user['site_color'] ?? setting('site_color', DEFAULT_SITE_COLOR);

$error = '';
$success = '';

// Handle POST: Profile Updates
if (isPost()) {
    try {
        requireCsrf();

        // 1. Check if updating details
        if (post('action') === 'update_profile') {
            $firstName = sanitizeString(post('first_name'));
            $lastName  = sanitizeString(post('last_name'));
            $phone     = sanitizeString(post('phone'));
            $username  = sanitizeString(post('username'));

            if (empty($firstName) || empty($lastName) || empty($phone) || empty($username)) {
                setFlash('error', "All fields are required.");
                redirectTo('profile');
            } else {
                $data = [
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'phone'      => $phone,
                    'username'   => $username
                ];
                
                $result = Ourcr\User::updateProfile($user['id'], $data);
                if ($result) {
                    setFlash('success', "Profile updated successfully.");
                    auditLog('PROFILE_UPDATED', "User updated profile details", 'users', $user['id']);
                    redirectTo('profile');
                } else {
                    setFlash('error', "Failed to update profile details or details match existing user.");
                    redirectTo('profile');
                }
            }
        }
        
        // 3. Check if uploading avatar
        elseif (post('action') === 'update_avatar') {
            if (!empty($_FILES['avatar']['name'])) {
                $result = Ourcr\User::uploadAvatar($user['id'], $_FILES['avatar']);
                if ($result['success']) {
                    setFlash('success', 'Profile picture updated.');
                    redirectTo('profile');
                } else {
                    setFlash('error', $result['error']);
                    redirectTo('profile');
                }
            } else {
                setFlash('error', "Please select an image file to upload.");
                redirectTo('profile');
            }
        }

    } catch (Throwable $e) {
        writeLog(LOG_CHAN_ERROR, 'error', 'Profile update exception: ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine()
        ]);
        setFlash('error', 'A technical error occurred.');
        redirectTo('profile');
    }
}

$avatarUrl = !empty($user['avatar'])
    ? AVATAR_URL . e($user['avatar'])
    : APP_URL . '/assets/images/avatar-default.png';

include INCLUDES_PATH . '/header.php';
?>
<div class="app-wrapper">
    <?php include INCLUDES_PATH . '/sidebar.php'; ?>
    
    <main class="app-main">
        <?php include INCLUDES_PATH . '/navbar.php'; ?>
        
        <div class="app-content">
            <div class="row fade-in-up">
                
                <!-- Profile Summary Card -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-16 mb-4 text-center">
                        <div class="card-body p-4">
                            <!-- Avatar upload preview wrapper -->
                            <div class="mb-3 d-inline-block position-relative" id="avatar-wrapper">
                                <!-- Avatar image -->
                                <img src="<?= $avatarUrl ?>"
                                     alt="<?= e($user['first_name']) ?>"
                                     id="avatar-img"
                                     class="rounded-circle border border-4 shadow-sm"
                                     style="width: 110px; height: 110px; object-fit: cover;"
                                     onerror="this.src='<?= APP_URL ?>/assets/images/avatar-default.png'">

                                <!-- Camera button -->
                                <label for="avatar-input"
                                       id="avatar-label"
                                       class="btn btn-sm rounded-circle p-0 d-flex align-items-center justify-content-center border-0 position-absolute bottom-0 end-0"
                                       style="width: 32px; height: 32px; cursor: pointer; background-color: var(--site-color, #DC2626);">
                                    <i class="fas fa-camera text-white small"></i>
                                </label>

                                <!-- Hidden file input — CSRF token kept here for JS to read -->
                                <input type="hidden" id="avatar-csrf" value="<?= csrfToken() ?>">
                                <input type="file" id="avatar-input" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" class="d-none">
                            </div>

                            <script>
                            (function () {
                                var input    = document.getElementById('avatar-input');
                                var label    = document.getElementById('avatar-label');
                                var csrfVal  = document.getElementById('avatar-csrf').value;
                                var endpoint = '<?= APP_URL ?>/api/upload_avatar.php';

                                input.addEventListener('change', function () {
                                    if (!input.files || !input.files[0]) return;

                                    label.style.pointerEvents = 'none';
                                    label.style.opacity = '0.6';
                                    input.disabled = true;

                                    var fd = new FormData();
                                    fd.append('avatar', input.files[0]);
                                    fd.append('<?= CSRF_TOKEN_NAME ?>', csrfVal);

                                    fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
                                        .then(function (r) { return r.json(); })
                                        .then(function (data) {
                                            if (data.success) {
                                                window.location.reload();
                                            } else {
                                                alert(data.message || 'Upload failed. Please try again.');
                                                label.style.pointerEvents = '';
                                                label.style.opacity = '';
                                                input.disabled = false;
                                                input.value = '';
                                            }
                                        })
                                        .catch(function () {
                                            alert('Network error. Please try again.');
                                            label.style.pointerEvents = '';
                                            label.style.opacity = '';
                                            input.disabled = false;
                                            input.value = '';
                                        });
                                });
                            }());
                            </script>

                            <h5 class="fw-bold mb-1"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></h5>
                            <p class="text-muted small mb-3">@<?= e($user['username']) ?></p>
                            <span class="badge bg-light text-dark mb-4 py-2 px-3 border rounded-pill">Role: <?= ucfirst($user['role']) ?></span>

                            <hr class="my-3">

                            <div class="text-start small text-muted">
                                <div class="mb-2"><strong>Email Address:</strong><br><span class="text-dark"><?= e($user['email']) ?></span></div>
                                <div class="mb-2"><strong>UUID Reference:</strong><br><span class="font-monospace text-dark"><?= e($user['uuid']) ?></span></div>
                                <div><strong>Join Date:</strong><br><span class="text-dark"><?= formatDate($user['created_at']) ?></span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Customisation Form -->
                <div class="col-lg-8">


                    <!-- Form 1: Details -->
                    <div class="card border-0 shadow-sm rounded-16 mb-4">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-4">Personal Information</h5>
                            
                            <form method="POST" action="<?= APP_URL ?>/profile">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update_profile">

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="first_name" class="form-label small">First Name</label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?= e($user['first_name']) ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="last_name" class="form-label small">Last Name</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?= e($user['last_name']) ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="username" class="form-label small">Username</label>
                                        <input type="text" class="form-control" id="username" name="username" value="<?= e($user['username']) ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="phone" class="form-label small">Phone Number</label>
                                        <input type="text" class="form-control" id="phone" name="phone" value="<?= e($user['phone']) ?>" required>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-danger mt-4 px-4 bg-site-color border-0 py-2 rounded-8">
                                    Save Changes
                                </button>
                            </form>
                        </div>
                    </div>



                </div>
            </div>
        </div>
    </main>
</div>

<!-- Mobile Bottom Navigation -->

<?php include INCLUDES_PATH . '/footer.php'; ?>


