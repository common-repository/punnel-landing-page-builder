<?php
/*
Template Name: Null Template - Punnel
Template Post Type: post, page, product, property
*/
?>
<?php
	/*
	while ( have_posts() ) : the_post();	
		the_content();
	endwhile;
	*/
	$post = get_post(get_the_ID());
	echo $post->post_content;
?>