<?php
namespace SGW_Sales\db;

use Anorm\Anorm;
use Anorm\DataMapper;
use Anorm\Model;

class CountModel extends Model
{

    /**
     * @param \PDO|null $pdo Anorm's QueryBuilder gives every model it makes the
     *   connection it was made with; without one the default connection is used.
     */
    public function __construct(?\PDO $pdo = null)
    {
        $pdo = $pdo ?: Anorm::pdo();
        parent::__construct($pdo, DataMapper::createByClass($pdo, $this, DB::tablePrefix()));
    }

    /** @var int */
    public $count;


}