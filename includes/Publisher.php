<?php

class Publisher
{
    public function publish($title, $content, $image, $category = 1, $publish_type = 'draft')
    {
        error_log('#UPLOAD# path: '.$image, 3, plugin_dir_path(__FILE__)."logs/error.log");

        $listCats = [];
        if (is_array($category)) {
            foreach ($category as $key => $value) {
                $listCats[] = $value;
            }
        }

        $post = [
            'post_title' => $title,
            'post_content' => $content,
            'post_category' => $listCats,
            'post_status' => $publish_type,
            'post_author' => get_current_user_id(),
        ];
        
        //temporarily disable
        remove_filter('content_save_pre', 'wp_filter_post_kses');
        remove_filter('content_filtered_save_pre', 'wp_filter_post_kses');

        $idReturn = wp_insert_post($post, false);
        if ($idReturn == 0) {
            return false;
        }

        // attach image to post if $image is not empty
        if ($image != "") {
            $filetype = wp_check_filetype(basename($filename), null);
            $wp_upload_dir = wp_upload_dir();
            $attachment = [
                'guid'           => $wp_upload_dir['url'] . '/' . basename($filename),
                'post_mime_type' => $filetype['type'],
                'post_title'     => preg_replace('/\.[^.]+$/', '', basename($filename)),
                'post_content'   => '',
                'post_status'    => 'inherit'
            ];
            $attach_id = wp_insert_attachment($attachment, $filename, $parent_post_id);
            $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
            wp_update_attachment_metadata($attach_id, $attach_data);
            set_post_thumbnail($idReturn, $attach_id);
        }
        
        //bring it back once you're done posting
        add_filter('content_save_pre', 'wp_filter_post_kses');
        add_filter('content_filtered_save_pre', 'wp_filter_post_kses');
    }
}
