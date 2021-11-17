<?php
namespace SGW\common\Pager;

use Anorm\QueryBuilder;

/**********************************************************************
    Copyright (C) FrontAccounting, LLC.
    Released under the terms of the GNU General Public License, GPL, 
    as published by the Free Software Foundation, either version 3 
    of the License, or (at your option) any later version.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
    See the License here <http://www.gnu.org/licenses/gpl-3.0.html>.
 ***********************************************************************/

class ModelPager /*extends db_pager */ implements PagerInterface
{
    var $name;
    var $columns;       // column definitions (head, type, order)

    var $marker;        // marker check function
    var $marker_txt;
    var $marker_class;
    var $notice_class;
    var $width;         // table width (default '95%')
    var $header_fun;    // additional row between title and body
    var $header_class;
    var $row_fun;       // Function for row preprocessing
    var $footer_fun;
    var $footer_class;

    // Pager
    public $pagerData;

    var $page_len,
        $rec_count;

    // and before first query.
    var $inactive_ctrl = false;
    var $main_tbl;        // table and key field name for inactive ctrl and edit/delete links

    /** @var string */
    private $className;
    /** @var QueryBuilder */
    private $query;

    private $queryResult = [];

    function __construct(string $name, string $className, QueryBuilder $query, int $rec_count, array $cols)
    {
        $this->name = $name;
        $this->className = $className;
        $this->query = $query;
        $this->rec_count = $rec_count;
        $this->page_len = user_query_size();

        $this->set_columns($cols);

        $dataClass = get_class($_SESSION[$name]);
        if (!isset($_SESSION[$name]) || $dataClass != PagerData::class) {
            $_SESSION[$name] = new PagerData();
            $this->pagerData = $_SESSION[$name];
            $this->pagerData->max_page = $this->page_len ? ceil($this->rec_count / $this->page_len) : 1;
            $this->set_page(1);
        } else {
            $this->pagerData = $_SESSION[$name];
            $this->pagerData->max_page = $this->page_len ? ceil($this->rec_count / $this->page_len) : 1;
        }

        $this->width = "95%";

        global $SysPrefs;

        if ($SysPrefs->go_debug) { // FIX - need column name parsing, but for now:
            // check if field names are set explicite in col def
            // for all initially ordered columns
            foreach ($this->columns as $col) {
                if (
                    isset($col['ord']) && $col['ord'] != ''
                    &&  !isset($col['name'])
                ) {
                    display_warning("Result field names must be set for all initially ordered db_pager columns.");
                }
            }
        }
    }

    //	Set query result page
    function change_page($page = null)
    {
        $this->set_page($page);
        $this->query();
        return true;
    }

    //	Change sort column direction 
    //	in order asc->desc->none->asc
    function sort_table($col)
    {

        $max_priority = 0;
        foreach ($this->columns as $id => $_col) {
            if (!isset($_col['ord_priority'])) continue;
            $max_priority = max($max_priority, $_col['ord_priority']);
        };

        $ord = $this->columns[$col]['ord'];
        $this->columns[$col]['ord_priority']  = $max_priority + 1; // set priority , higher than anything else
        $ord = ($ord == '') ? 'asc' : (($ord == 'asc') ? 'desc' : '');
        $this->columns[$col]['ord'] = $ord;
        $this->set_page(1);
        $this->query();
        return true;
    }

    // Query database
    function query()
    {
        global $Ajax;

        $Ajax->activate("_{$this->name}_span");
        $this->data = array();

        if ($this->rec_count == 0) return true;

        $queryFunction = $this->className . '::findByQuery';
        $this->queryResult = $queryFunction($this->query, $this->page_len, $this->recordOffset());
    }

    public function generator() {
        return $this->queryResult;
    }

    //	Calculates page numbers for html controls.
    function set_page($to)
    {
        switch ($to) {
            case 'next':
                $page = $this->pagerData->curr_page + 1;
                break;
            case 'prev':
                $page = $this->pagerData->curr_page - 1;
                break;
            case 'last':
                $page = $this->pagerData->last_page;
                break;
            default:
                if (is_numeric($to)) {
                    $page = $to;
                    break;
                }
            case 'first':
                $page = 1;
                break;
        }
        if ($page < 1)
            $page = 1;
        $max = $this->pagerData->max_page;
        if ($page > $max)
            $page = $max;
        $this->pagerData->curr_page = $page;
        $this->pagerData->next_page = ($page < $max) ? $page + 1 : null;
        $this->pagerData->prev_page = ($page > 1) ? ($page - 1) : null;
        $this->pagerData->last_page = ($page < $max) ? $max : null;
        $this->pagerData->first_page = ($page != 1) ? 1 : null;
    }

    private function recordOffset() {
        return ($this->pagerData->curr_page - 1) * $this->page_len;
    }

    public function __get($name)
    {
        if (!property_exists($this, $name)) {
            return $this->pagerData->$name;
        }
        return $this->$name;
    }

    //	Set column definitions
    //  $flds: array($ fldname1, fldname2=>type,...)
    private function set_columns($flds)
    {
        $this->columns = array();
        if (!is_array($flds)) {
            $flds = array($flds);
        }
        foreach ($flds as $colnum => $coldef) {
            if (is_string($colnum)) {    // 'colname'=>params
                $h = $colnum;
                $c = $coldef;
            } else {            //  n=>params
                if (is_array($coldef)) {
                    $h = '';
                    $c = $coldef;
                } else {
                    $h = $coldef;
                    $c = 'text';
                }
            }
            if (is_string($c))             // params is simple column type
                $c = array('type' => $c);

            if (!isset($c['type']))
                $c['type'] = 'text';

            switch ($c['type']) {
                case 'inactive':
                    $this->inactive_ctrl = true;
                case 'insert':
                default:
                    $c['head'] = $h;
                    break;
                case 'skip':        // skip the column (no header)
                    unset($c['head']);
                    break;
            }
            $this->columns[] = $c;
        }
    }
    //
    //	Set current page in response to user control.
    //
    function select_records()
    {
        global $Ajax;

        $page = find_submit($this->name . '_page_', false);
        $sort = find_submit($this->name . '_sort_', true);
        if ($page) {
            $this->change_page($page);
            if (
                $page == 'next' && !$this->next_page ||
                $page == 'last' && !$this->last_page
            )
                set_focus($this->name . '_page_prev');
            if (
                $page == 'prev' && !$this->prev_page ||
                $page == 'first' && !$this->first_page
            )
                set_focus($this->name . '_page_next');
        } elseif ($sort != -1) {
            $this->sort_table($sort);
        } else
            $this->query();
    }
    //
    //	Set check function to mark some rows.
    //	
    function set_marker($func, $notice = '', $markercl = 'overduebg', $msgclass = 'overduefg')
    {
        $this->marker = $func;
        $this->marker_txt = $notice;
        $this->marker_class = $markercl;
        $this->notice_class = $msgclass;
    }
    //
    //	Set handler to display additional row between titles and pager body.
    //	Return array of column contents.
    //
    function set_header($func, $headercl = 'inquirybg')
    {
        $this->header_fun = $func;
        $this->header_class = $headercl;
    }
    //
    //	Set handler to display additional row between pager body and navibar.
    //	Return array of column contents.
    //
    function set_footer($func, $footercl = 'inquirybg')
    {
        $this->footer_fun = $func;
        $this->footer_class = $footercl;
    }
    //
    //	Setter for table editors with inactive cell control.
    //
    function set_inactive_ctrl($table, $key)
    {
        $this->inactive_ctrl = array('table' => $table, 'key' => $key);
    }
    //
    //	Helper for display inactive control cells
    //
    // TODO Review consider moving this to ControlRenderer::pager CP 2021-11
    function inactive_control_cell(&$row)
    {
        if ($this->inactive_ctrl) {

            global    $Ajax;

            $key = $this->key ?
                $this->key : $this->columns[0]['name'];        // TODO - support for complex keys
            $id = $row[$key];
            $table = $this->main_tbl;
            $name = "Inactive" . $id;
            $value = $row['inactive'] ? 1 : 0;

            if (check_value('show_inactive')) {
                if (isset($_POST['LInact'][$id]) && (get_post('_Inactive' . $id . '_update') ||
                    get_post('Update')) && (check_value('Inactive' . $id) != $value)) {
                    update_record_status($id, !$value, $table, $key);
                    $value = !$value;
                }
                echo '<td align="center">' . checkbox(null, $name, $value, true, '')
                    . hidden("LInact[$id]", $value, false) . '</td>';
            }
        } else
            return '';
    }

    //-----------------------------------------------------------------------------
    //	Creates new db_pager $_SESSION object on first page call.
    //  Retrieves from $_SESSION var on subsequent $_POST calls
    //
    //  $name - base name for pager controls and $_SESSION object name
    //  $sql  - base sql for data inquiry. Order of fields implies
    //		pager columns order.
    //	$coldef - array of column definitions. Example definitions
    //		Column with title 'User name' and default text format:
    //				'User name'
    //		Skipped field from sql query. Data for the field is not displayed:
    //				'dummy' => 'skip'
    //		Column without title, data retrieved form row data with function func():
    //	 			array('fun'=>'func')
    // 		Inserted column with title 'Some', formated with function rowfun().
    //  	formated as date:
    //				'Some' => array('type'=>'date, 'insert'=>true, 'fun'=>'rowfun')
    // 		Column with name 'Another', formatted as date, 
    // sortable with ascending start order (available orders: asc,desc, '').
    //				'Another' => array('type'=>'date', 'ord'=>'asc')
    //
    //	All available column format types you will find in db_pager_view.inc file.
    //		If query result has more fields than count($coldef), rest of data is ignored
    //  during display, but can be used in format handlers for 'spec' and 'insert' 
    //	type columns.

    //
    //	Force pager initialization.
    //
    static function refresh_pager($name)
    {
        if (isset($_SESSION[$name])) {
            unset($_SESSION[$name]);
        }
    }
};
