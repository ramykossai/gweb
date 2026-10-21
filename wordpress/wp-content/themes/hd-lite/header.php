<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

<!-- Meta Pixel (fixed 2026-10-10):
     1× loader + N init + 1× PageView (no PageView multiplication).
     PRIMARY ads pixel first: 1674037769681206 — others kept as backup data sinks. -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '1674037769681206'); /* PRIMARY ads */
fbq('init', '934649599542099'); /* backup 100k 26/04 */
fbq('init', '1532095725206745'); /* backup Clean Wash Col 26/04 */
fbq('init', '764734576180477'); /* backup 09/04 */
fbq('init', '1937266787152792'); /* backup main 14 08/04 */
fbq('init', '1797620380449437'); /* backup LunaGrown 08/04 */
fbq('init', '6626188270741759'); /* backup flaviaviagenseturismo 08/04 */
fbq('init', '2013581432923139'); /* backup main 11 08/04 */
fbq('init', '1487474262895681'); /* backup 10 06/04 */
fbq('init', '813042218518550'); /* backup 8 06/04 */
fbq('init', '733224046448489'); /* backup ND Media PIXEL 06/04 */
fbq('init', '1268340343622788'); /* backup KKC PERFORMANCE 06/04 */
fbq('init', '1593429308544124'); /* backup DTX 03/04 */
fbq('init', '1070158655095599'); /* backup MemoryThief 03/04 */
fbq('init', '1403641677955422'); /* backup Datos CA 02/04 */
fbq('init', '34559434870338539'); /* backup */
fbq('init', '2109278629922879'); /* backup */
fbq('init', '959217423446347'); /* backup */
fbq('track', 'PageView');
</script>
<!-- End Meta Pixel Code -->

<?php wp_head(); ?>
</head>
<body <?php body_class(isset($args['body_class']) ? $args['body_class'] : ''); ?>>
<?php
if (function_exists('wp_body_open')) {
    wp_body_open();
}
?>
