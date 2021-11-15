<?php
namespace SGW\Common;

interface PagerInterface {

    function select_records();

    // Expected properties
    // $name
    // $width

    // $columns
    // $data

    // $header_fun
    // $header_class
    // $footer_fun
    // $footer_class

    // Pager
    // $first_page
    // $prev_page
    // $next_page
    // $last_page
    // $page_len
    // $curr_page
    // $rec_count

    // Optional properties
    // $inactive_ctrl
    // $marker_txt
    // $notice_class

}
