/**
 * OURCR ONLINE - User Dashboard Core JavaScript
 */

$(document).ready(function() {
    // Sidebar toggle (mobile view)
    $('#sidebarToggle').on('click', function(e) {
        e.preventDefault();
        $('#appSidebar').addClass('open');
        $('#sidebarOverlay').addClass('active');
    });

    $('#sidebarClose, #sidebarOverlay').on('click', function() {
        $('#appSidebar').removeClass('open');
        $('#sidebarOverlay').removeClass('active');
    });

    // 4-Digit PIN input auto-advance behavior
    $('.pin-digit').on('input', function() {
        const val = $(this).val().replace(/\D/g, '');
        $(this).val(val); // enforce digits only
        if (val.length === 1) {
            $(this).next('.pin-digit').focus();
        }
        combinePinDigits($(this).closest('form'));
    });

    $('.pin-digit').on('keydown', function(e) {
        if (e.key === 'Backspace' && !$(this).val()) {
            $(this).prev('.pin-digit').focus();
        }
    });

    $('.pin-digit').on('paste', function(e) {
        const paste = (e.originalEvent.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
        if (paste.length >= 4) {
            const digits = $(this).closest('.pin-input-group').find('.pin-digit');
            digits.each(function(index) {
                $(this).val(paste[index] || '');
            });
            digits.last().focus();
            combinePinDigits($(this).closest('form'));
            e.preventDefault();
        }
    });

    function combinePinDigits(form) {
        const digits = form.find('.pin-digit');
        if (digits.length === 4) {
            let pinVal = '';
            digits.each(function() {
                pinVal += $(this).val();
            });
            form.find('input[name="transaction_pin"]').val(pinVal);
        }
    }

    // Dynamic copy utility
    $('[data-copy]').on('click', function(e) {
        e.preventDefault();
        const copyText = $(this).attr('data-copy');
        const $btn = $(this);
        const originalHtml = $btn.html();

        navigator.clipboard.writeText(copyText).then(function() {
            $btn.html('<i class="fas fa-check"></i> Copied!');
            $btn.addClass('btn-success').removeClass('btn-outline-primary btn-primary btn-secondary');
            setTimeout(function() {
                $btn.html(originalHtml);
                $btn.removeClass('btn-success');
            }, 2000);
        }, function() {
            Swal.fire({
                icon: 'error',
                title: 'Copy Failed',
                text: 'Please manually copy the text: ' + copyText
            });
        });
    });

    // Fetch dynamic notifications unread count every 60s
    function fetchUnreadNotifications() {
        if (typeof APP_URL !== 'undefined') {
            $.ajax({
                url: APP_URL + '/api/notifications.php?action=count',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    const badge = $('#notifBadge');
                    if (badge.length) {
                        if (response.success && response.count > 0) {
                            badge.text(response.count > 9 ? '9+' : response.count).show();
                        } else {
                            badge.hide();
                        }
                    }
                },
                error: function() {
                    // silently fail
                }
            });
        }
    }

    // Initialize notification polling
    if ($('#notifBadge').length) {
        fetchUnreadNotifications();
        setInterval(fetchUnreadNotifications, 60000);
    }

    // Set CSRF token headers on all JQuery AJAX queries
    const csrfToken = $('meta[name="csrf-token"]').attr('content');
    if (csrfToken) {
        $.ajaxSetup({
            headers: {
                'X-CSRF-Token': csrfToken
            }
        });
    }
});
