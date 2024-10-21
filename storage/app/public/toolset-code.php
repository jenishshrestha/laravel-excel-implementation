<?php

function get_image_url($image_id)
{
    return ! empty($image_id) ? wp_get_attachment_url($image_id) : '';
}

function get_related_slide($post_id)
{
    $args = [
        'post_type' => 'slide',
        'meta_key' => '_wpcf_belongs_our-people_id',
        'meta_value' => $post_id,
        'posts_per_page' => -1,
    ];

    $related_slides = get_posts($args);
    if (empty($related_slides)) {
        return null;
    }

    if (count($related_slides) == 1) {
        return $related_slides[0];
    }

    foreach ($related_slides as $slide) {
        if (get_post_meta($slide->ID, 'wpcf-sliders_order', true) == 1) {
            return $slide;
        }
    }

    return $related_slides[0];
}

function get_related_slide_title($post_id)
{
    $slide_to_use = get_related_slide($post_id);
    return $slide_to_use ? get_the_title($slide_to_use->ID) : '';
}

function get_related_slide_media_type($post_id)
{
    $slide_to_use = get_related_slide($post_id);
    if (! $slide_to_use) {
        return '';
    }

    $video_file = get_post_meta($slide_to_use->ID, 'wpcf-slide-video-url', true);
    $slide_image_url = get_post_meta($slide_to_use->ID, 'wpcf-slide-image', true);

    return ! empty($video_file) ? 'video' : (! empty($slide_image_url) ? 'image' : '');
}


function get_related_slide_image($post_id)
{
    $slide_to_use = get_related_slide($post_id);

    if (! $slide_to_use) {
        return '';
    }

    return get_post_meta($slide_to_use->ID, 'wpcf-slide-image', true);
}

function get_related_slide_video($post_id)
{
    $slide_to_use = get_related_slide($post_id);

    if (! $slide_to_use) {
        return '';
    }

    return get_post_meta($slide_to_use->ID, 'wpcf-slide-video-url', true);
}