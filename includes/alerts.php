<?php
/**
 * OURCR ONLINE - Alert / Flash Message Renderer
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

/**
 * Render Bootstrap 5 alert for flash messages
 */
function renderAlerts(): void
{
    $flashes = getFlash();
    if (empty($flashes)) {
        return;
    }

    // Check if custom success details modal is active on page
    $hasCustomModal = isset($_SESSION['last_txn_success']);

    foreach ($flashes as $flash) {
        $type = $flash['type'];
        
        // Suppress success flash if there is a custom success receipt modal
        if ($type === 'success' && $hasCustomModal) {
            continue;
        }

        if ($type === 'success') {
            echo '<div class="alert alert-success d-flex align-items-center gap-3 border-0 py-3 px-4 mb-4 rounded-16 shadow-sm animate-fade-in" style="background: rgba(22, 163, 74, 0.1); border-left: 5px solid #16a34a !important; color: #16a34a; font-size: 14px;">'
                . '<i class="fas fa-check-circle fs-5"></i>'
                . '<div class="fw-medium">' . e($flash['message']) . '</div>'
                . '</div>';
        } else {
            // error, warning, info
            $color = '#DC2626'; // Brand Red
            $icon  = match ($type) {
                'warning' => 'fa-exclamation-triangle',
                'info'    => 'fa-info-circle',
                default   => 'fa-exclamation-circle',
            };
            echo '<div class="alert alert-danger d-flex align-items-center gap-3 border-0 py-3 px-4 mb-4 rounded-16 shadow-sm animate-fade-in" style="background: rgba(220, 38, 38, 0.1); border-left: 5px solid ' . $color . ' !important; color: ' . $color . '; font-size: 14px;">'
                . '<i class="fas ' . $icon . ' fs-5"></i>'
                . '<div class="fw-medium">' . e($flash['message']) . '</div>'
                . '</div>';
        }
    }
}

/**
 * Output SweetAlert2 JS for flash messages on page load
 */
function renderSweetAlerts(): void
{
    // Only allow SweetAlert in the admin panel to keep admin console alerts functional
    $isAdmin = (strpos($_SERVER['REQUEST_URI'] ?? '', '/admin') !== false);
    if (!$isAdmin) {
        return;
    }

    $flashes = getFlash();
    if (empty($flashes)) {
        return;
    }

    echo '<script>';
    foreach ($flashes as $flash) {
        $type = in_array($flash['type'], ['success', 'error', 'warning', 'info']) ? $flash['type'] : 'info';
        $message = addslashes(e($flash['message']));
        
        $background = '#dc3545'; // red color
        $icon = match($type) {
            'success' => 'success',
            'error'   => 'error',
            'warning' => 'warning',
            default   => 'info',
        };

        echo "Swal.fire({
            toast: true,
            position: 'bottom-end',
            icon: '{$icon}',
            title: '{$message}',
            showConfirmButton: false,
            timer: 5000,
            timerProgressBar: true,
            background: '{$background}',
            color: '#fff',
            iconColor: '#fff',
            showClass: {
                popup: 'animate__animated animate__fadeInUp animate__faster'
            },
            hideClass: {
                popup: 'animate__animated animate__fadeOutDown animate__faster'
            }
        });";
    }
    echo '</script>';
}
