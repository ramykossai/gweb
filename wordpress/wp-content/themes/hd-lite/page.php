<?php
/**
 * Default page template (legal + generic content).
 */
get_header(null, ['body_class' => 'hd-legal-body']);
?>
<main class="hd-shop">
<?php
while (have_posts()) :
    the_post();
    echo '<article class="hv-legal-article">';
    the_content();
    echo '</article>';
endwhile;
?>
</main>
<?php
if (function_exists('hd_site_legal_footer')) {
    hd_site_legal_footer();
}
get_footer();
