<?php
namespace exieros\mstparser;

require_once __DIR__ . '/vendor/autoload.php';

use Exception;

class StreamToSQLDump 
{   
    private $count = 0;
    private $fields = [];
    private $records = [];

    public function __construct( string $pathToSql, bool $addDropCreateLines = true, bool $deleteFileIfExist = true, int $chunkSize = 6000 ){
        $this->lineGlue = PHP_EOL;
        $this->pathToSql = $pathToSql;
        //$this->lineGlue = '';

        if( $deleteFileIfExist && file_exists( $this->pathToSql ) ){
            unlink( $this->pathToSql );
        }

        $this->chunkSize = $chunkSize;

        if ( $addDropCreateLines ){
            $initSql = <<<SQL
            DROP TABLE IF EXISTS `records`;
            DROP TABLE IF EXISTS `fields`;
            SQL;
            

            $initSql .= $this->lineGlue . $this->lineGlue;

            $initSql .= <<<SQL
            CREATE TABLE `fields` (
                `id` bigint NOT NULL AUTO_INCREMENT,
                `num` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                `subkey` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                `record_id` int NOT NULL,
                `value` text CHARSET utf8mb4,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL;

            $initSql .= $this->lineGlue . $this->lineGlue;

            $initSql .= <<<SQL
            CREATE TABLE `records` (
                `id` int NOT NULL AUTO_INCREMENT,
                `guid` text,
                `created_at_irbis` int NOT NULL DEFAULT '0',
                `modified_at_irbis` int NOT NULL DEFAULT '0',
                `dbname` text,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL;

            $initSql .= $this->lineGlue . $this->lineGlue;

            $this->addData($initSql);
        }
    }

    private function escape($str){
        $str = str_replace('\\', '\\\\', $str );
        $str = str_replace('\'', '\'\'', $str );
        $str = mb_convert_encoding( $str, 'UTF-8', 'UTF-8');
        return "'" . $str . "'";
    }

    public function addRecord( $record ){
        ++$this->count;

        foreach ($record['fields'] as $num => $tag_a) {
            foreach ($tag_a as $tag_aa) {
                foreach ($tag_aa as $char => $value) {
                    $value = $this->escape($value);
                    $char = $this->escape($char);
                    $this->fields[] = "( '$num', $char, {$this->count}, $value )";
                }
            }
        }

        $this->records[] = "( '{$record['guid']}', {$record['created_at']}, {$record['modified_at']}, '{$record['db']}' )";

        while( count( $this->fields ) >= $this->chunkSize ){
            $chunk = array_splice($this->fields, 0, $this->chunkSize);
            $chunk_sql = $this->lineGlue . 'INSERT INTO `fields` (num, subkey, record_id, value) VALUES ' . $this->lineGlue;
            $chunk_sql .= implode( ',' . $this->lineGlue, $chunk ) . ';' . $this->lineGlue;
            $this->addData($chunk_sql);
        }

        while( count( $this->records ) >= $this->chunkSize ){
            $chunk = array_splice($this->records, 0, $this->chunkSize);
            $chunk_sql = $this->lineGlue . 'INSERT INTO `records` (guid, created_at_irbis, modified_at_irbis, dbname) VALUES ' . $this->lineGlue;
            $chunk_sql .= implode( ',' . $this->lineGlue, $chunk ) . ';' . $this->lineGlue;
            $this->addData($chunk_sql);
        }
    }

    public function dumpLeftovers(){
        $chunk_sql = $this->lineGlue . 'INSERT INTO `fields` (num, subkey, record_id, value) VALUES ' . $this->lineGlue;
        $chunk_sql .= implode( ',' . $this->lineGlue, $this->fields ) . ';' . $this->lineGlue;
        $this->addData($chunk_sql);

        $chunk_sql = $this->lineGlue . 'INSERT INTO `records` (guid, created_at_irbis, modified_at_irbis, dbname) VALUES ' . $this->lineGlue;
        $chunk_sql .= implode( ',' . $this->lineGlue, $this->records ) . ';' . $this->lineGlue;
        $this->addData($chunk_sql);
    }

    private function addData( string $data ){
        file_put_contents( $this->pathToSql, $data, FILE_APPEND | LOCK_EX);
    }
}