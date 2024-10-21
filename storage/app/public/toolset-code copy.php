<?php

function get_related_slide_data($post_id)
{
    // Query for all 'slide' posts that have the current 'ourpeople' post as their parent
    $args = array(
        'post_type' => 'slide',
        'meta_key' => '_wpcf_belongs_our-people_id',
        'meta_value' => $post_id,
        'posts_per_page' => -1, // Fetch all related slides
    );

    // Fetch all related slides
    $related_slides = get_posts($args);

    // Check if any related slides exist
    if (! empty($related_slides)) {
        $slide_to_use = null;

        // Condition:
        // 1.If there are multiple entries, please use the data from the first slide (filtered by 'Order 1').
        // 2.If both a video and an image are present in 'Post Relationship > Media Slider > Video File' and 'Post Relationship > Media Slider > Slide Image,' set the dropdown to 'Video.' If only an image is present in 'Post Relationship > Media Slider > Slide Image' and no video is available, set the dropdown to 'Image.'"

        if (count($related_slides) == 1) {
            $slide_to_use = $related_slides[0];
        } else {
            foreach ($related_slides as $slide) {
                $order_value = get_post_meta($slide->ID, 'wpcf-sliders_order', true);
                if ($order_value == 1) {
                    $slide_to_use = $slide;
                    break;
                }
            }

            // If no slide with 'order' = 1 is found, fall back to the first slide
            if (! $slide_to_use) {
                $slide_to_use = $related_slides[0];
            }
        }

        // Now we have the slide to use
        if ($slide_to_use) {
            $slide_title = get_the_title($slide_to_use->ID);
            $video_file = get_post_meta($slide_to_use->ID, 'wpcf-spotlight_video', true); // Video file field
            $slide_image_id = get_post_meta($slide_to_use->ID, 'wpcf-spotlight_image', true); // Image field ID

            // Initialize variables for image and video
            $media_type = '';
            $spotlight_image_url = '';
            $spotlight_video = '';

            // Check if a video is present
            if (! empty($video_file)) {
                $spotlight_video = $video_file;
            }

            // Check if an image is present
            if (! empty($slide_image_id)) {
                $spotlight_image_url = wp_get_attachment_url($slide_image_id);
            }

            // Set the media type based on availability of video and image
            if (! empty($spotlight_video)) {
                // If a video is present, set media type to Video
                $media_type = 'Video';
            } elseif (! empty($spotlight_image_url)) {
                // If no video but an image is present, set media type to Image
                $media_type = 'Image';
            }

            // example the output
            $output = "Title: " . $slide_title . ", Media Type: " . $media_type;

            // Add video or image to the output based on media type
            if ($media_type == 'Video') {
                $output .= ", Spotlight Video: " . $spotlight_video;
            } elseif ($media_type == 'Image') {
                $output .= ", Spotlight Image: " . $spotlight_image_url;
            }

            return $output;
        }
    }

    return ''; // Return empty if no related slide is found
}