<?php
/**
 * OURCR ONLINE - Pagination Component
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

/**
 * Render a Bootstrap 5 pagination bar
 *
 * @param array  $pages  Result of paginate()
 * @param string $urlPattern  URL with {page} placeholder, e.g. '/transactions?page={page}'
 */
function renderPagination(array $pages, string $urlPattern): void
{
    if ($pages['last'] <= 1) {
        return;
    }

    $current = $pages['current'];
    $last    = $pages['last'];

    echo '<nav aria-label="Page navigation"><ul class="pagination justify-content-center flex-wrap">';

    // Previous
    if ($pages['has_prev']) {
        $url = str_replace('{page}', $pages['prev'], $urlPattern);
        echo '<li class="page-item"><a class="page-link" href="' . $url . '" aria-label="Previous">&laquo;</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
    }

    // Page numbers — show a window around current page
    $window = 2;
    $start  = max(1, $current - $window);
    $end    = min($last, $current + $window);

    if ($start > 1) {
        $url = str_replace('{page}', 1, $urlPattern);
        echo '<li class="page-item"><a class="page-link" href="' . $url . '">1</a></li>';
        if ($start > 2) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        $url    = str_replace('{page}', $i, $urlPattern);
        $active = $i === $current ? ' active' : '';
        echo '<li class="page-item' . $active . '">';
        echo $active
            ? '<span class="page-link">' . $i . '</span>'
            : '<a class="page-link" href="' . $url . '">' . $i . '</a>';
        echo '</li>';
    }

    if ($end < $last) {
        if ($end < $last - 1) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        $url = str_replace('{page}', $last, $urlPattern);
        echo '<li class="page-item"><a class="page-link" href="' . $url . '">' . $last . '</a></li>';
    }

    // Next
    if ($pages['has_next']) {
        $url = str_replace('{page}', $pages['next'], $urlPattern);
        echo '<li class="page-item"><a class="page-link" href="' . $url . '" aria-label="Next">&raquo;</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
    }

    echo '</ul></nav>';

    // Info
    echo '<p class="text-center text-muted small mt-2">Showing '
        . number_format($pages['from']) . ' – ' . number_format($pages['to'])
        . ' of ' . number_format($pages['total']) . ' records</p>';
}
