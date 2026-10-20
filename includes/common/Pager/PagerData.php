<?php
namespace SGW\common\Pager;

class PagerData
{
    public $curr_page;
    public $max_page;
    public $last_page;
    public $prev_page;
    public $next_page;
    public $first_page;
    /**
     * The sorted columns, kept across requests as the columns are not:
     * column index => [ord ('asc' or 'desc'), ord_priority].
     * @var array<int, array{0: string, 1: int}>
     */
    public $sort = [];
}