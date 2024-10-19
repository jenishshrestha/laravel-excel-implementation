Got it! If you're planning to stick with the old Toolset post-relationship model, you can continue using the custom
fields approach to retrieve the related posts. Since you want to work with the older Toolset structure, here's a refined
solution using the meta field _wpcf_belongs_<posttype>_id to get related posts.

    Solution for the Old Toolset Model
    In the old Toolset model, relationships between posts were stored in post meta as custom fields. For instance, a
    child post might store the parent post ID using a custom field like _wpcf_belongs_people_id or
    _wpcf_belongs_slide_id.

    Here’s a detailed function that will work for the old Toolset system, assuming the relationship is stored in the
    custom field _wpcf_belongs_slide_id (you can adjust this if the field is different).

    Step-by-Step Code Implementation:
    php
    Copy code
    function get_related_slide_titles_old($post_id) {
    // Retrieve related slide IDs from the custom field '_wpcf_belongs_slide_id'
    $related_slide_ids = get_post_meta($post_id, '_wpcf_belongs_slide_id', true);

    // Check if any related slide IDs exist
    if (empty($related_slide_ids)) {
    return ''; // Return an empty string if no related slides are found
    }

    // Ensure the retrieved value is an array (in case there's only one ID)
    if (!is_array($related_slide_ids)) {
    $related_slide_ids = array($related_slide_ids);
    }

    // Collect the titles of the related 'slide' posts
    $titles = array();
    foreach ($related_slide_ids as $slide_id) {
    $titles[] = get_the_title($slide_id); // Get the title of each related slide
    }

    // Return the titles as a comma-separated string
    return implode(', ', $titles);
    }
    Explanation of the Code:
    Retrieving Related IDs:

    We use get_post_meta() to retrieve the related "slide" post IDs. This assumes the relationship field is
    _wpcf_belongs_slide_id, which stores the relationship between "ourpeople" and "slide". If the meta key is different,
    you'll need to update this part with the correct key.
    Check for Related Posts:

    If no related slides are found, the function returns an empty string.
    Handle Multiple Related Posts:

    If there's only one related post, the retrieved value might not be an array. We check this and convert it to an
    array if necessary.
    Retrieve Titles:

    We loop through the list of related post IDs and retrieve each post’s title using get_the_title().
    Output:

    The titles are joined into a comma-separated string and returned.
    Step 2: Use this Function in WP All Export
    In WP All Export, when setting up your export, choose the option to Export the value returned by a PHP function.
    Enter the following in the Function Editor (as shown in your screenshot):
    php
    Copy code
    <?php return get_related_slide_titles_old($post_id); ?>
    Step 3: Test Export
    Run the export and check the result to ensure the related "slide" post titles are being fetched and exported
    correctly.
    Troubleshooting:
    If you do not see the correct titles, double-check the meta key (_wpcf_belongs_slide_id) used to store the
    relationship. The key might vary depending on the exact setup of your old Toolset installation.

    You can use a plugin like Query Monitor or inspect your WordPress database to view the exact meta key structure for
    the relationships between posts.

    Summary:
    The function retrieves related "slide" posts using the custom field _wpcf_belongs_<posttype>_id from the old Toolset
        relationship structure.
        It works by fetching the related post IDs from the meta field and then retrieving the titles of the related
        posts.
        This solution allows you to continue using the old Toolset model without needing to migrate to the new
        relationships API.
        Let me know if you run into any issues or need further adjustments!