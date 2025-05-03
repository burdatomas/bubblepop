<?php get_header(); ?>
<main>
    <h1>Welcome to Bubblepop</h1>
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <article>
            <h2><?php the_title(); ?></h2>
            <?php the_content(); ?>
        </article>
    <?php endwhile; endif; ?>
</main>
<?php get_footer(); ?>