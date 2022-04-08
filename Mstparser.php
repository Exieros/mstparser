<?php
namespace exieros\mstparser;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . './StreamToSQLDump.php';

use Exception;
use Nelexa\Buffer\FileBuffer;

class Mstparser 
{
    private string $mst_path;
    private array $filters;
    private $iterator;
    
    public function __construct(){
        $this->filters = [];
        $this->databases = [];
        $this->skipEmptyValues = true;
        $this->datesAsTimestamps = true;
    }

    public function setSkipEmptyValue(bool $skip): self{
        $this->skipEmptyValues = $skip;
        return $this;
    }

    public function dumpToSQL(string $pathToSql = __DIR__ . './dump.sql', bool $addDropCreateLines = true, bool $deleteFileIfExist = true, int $chunkSize = 200):self{
        $this->writterSql = new \exieros\mstparser\StreamToSQLDump( $pathToSql, $addDropCreateLines, $deleteFileIfExist, $chunkSize );
        return $this;
    }

    public function setDatesAsTimestamps(bool $ts = true): self{
        $this->datesAsTimestamps = $ts;
        return $this;
    }

    public function setIterator(callable $iterator): self{
        $this->iterator = $iterator;
        return $this;
    }

    public function setDatabasesPath( $path ): self{
        $_path = $this->checkDirExist( $path );
        if( !$_path ){
            throw new Exception('Path not found!');
        }
        $this->databasesPath = $_path;
        return $this;
    }

    public function addDatabase( $databasename ): self{
        $dbpath = $this->databasesPath . '\\'. $databasename;
        if( !$this->checkDirExist( $dbpath ) ){
            throw new Exception('Path not found!');
        }

        $mstpath = $dbpath . '\\'. $databasename . '.MST';
        if( !file_exists( $mstpath ) ){
            throw new Exception('*.MST file not found');
        }

        $xrfpath = $dbpath . '\\'. $databasename . '.XRF';
        if( !file_exists( $xrfpath ) ){
            throw new Exception('*.XRF file not found');
        }

        $this->databases[] = [ $mstpath, $xrfpath, $databasename ];
        return $this;
    }

    public function addFilter(array $filter): self{
        if( count($filter) == 3 ){
            $this->filters[] = $filter;
        }
        return $this;
    }

    private function checkDirExist( $dir ){
        $path = realpath($dir);
        return ( $path !== false AND is_dir($path) ) ? $path : false;
    }

    private function readMasterFileHeader(): array{
        return [
            'NXTMFN' => $this->mst_buffer->getLong(),
            'NXT_LOW' => $this->mst_buffer->getUnsignedInt(),
            'NXT_HIGH' => $this->mst_buffer->getUnsignedInt(),
            'MFTYPE' => $this->mst_buffer->getUnsignedInt(),
            'RECCNT' => $this->mst_buffer->getUnsignedInt(),
            'MFCXX1' => $this->mst_buffer->getUnsignedInt(),
            'MFCXX2' => $this->mst_buffer->getUnsignedInt(),
            'MFCXX3' => $this->mst_buffer->getUnsignedInt()
        ];
    }

    private function readXrfRecord(): array{
        return [
            'XRF_LOW' => $this->xrf_buffer->getUnsignedInt(),
            'XRF_HIGH' => $this->xrf_buffer->getUnsignedInt(),
            'XRF_FLAGS' => $this->xrf_buffer->getUnsignedInt()
        ];
    }

    private function readRecordHeader(): array{
        return [
            'MFN' => $this->mst_buffer->getUnsignedInt(),
            'MFRL' => $this->mst_buffer->getUnsignedInt(),
            'MFB_LOW' => $this->mst_buffer->getUnsignedInt(),
            'MFB_HIGH' => $this->mst_buffer->getUnsignedInt(),
            'BASE' => $this->mst_buffer->getUnsignedInt(),
            'NVF' => $this->mst_buffer->getUnsignedInt(),
            'STATUS' => $this->mst_buffer->getUnsignedInt(),
            'VERSION' => $this->mst_buffer->getUnsignedInt()
        ];
    }

    private function checkFilter( $variableName, $variable ): bool{
        if( !count($this->filters) ){
            return true;
        }

        $filters = array_filter($this->filters, function($f) use ($variableName){
            return $f[0] == $variableName;
        });

        if( !count($filters) ){
            return true;
        }

        foreach ($filters as $filter) {
            if( $filter[0] == 'guid' && ( $filter[1] == '<' || $filter[1] == '>' ) ){
                continue;
            }
            if( $filter[0] != $variableName ){
                continue;
            }
            if( $filter[1] == '=' ){
                if( $variable == $filter[2] ){
                    return true;
                }
            }
            if( $filter[1] == '>' ){
                if( $variable > $filter[2] ){
                    return true;
                }
            }
            if( $filter[1] == '<' ){
                if( $variable < $filter[2] ){
                    return true;
                }
            }
        }
        return false;
    }

    public function start(){
        foreach ($this->databases as $db) {
            $this->mst_buffer = new FileBuffer( $db[0] );
            $this->mst_buffer->setReadOnly(true);

            $this->xrf_buffer = new FileBuffer( $db[1] );
            $this->xrf_buffer->setReadOnly(true);

            while( $this->xrf_buffer->position() <= $this->xrf_buffer->size() - 12 ){
                $xrf_rec = $this->readXrfRecord();

                if( $xrf_rec['XRF_FLAGS'] != 0 || $xrf_rec['XRF_LOW'] == 0 ){
                    continue;
                }

                $this->mst_buffer->setPosition( $xrf_rec['XRF_LOW'] );

                $recordHeader = $this->readRecordHeader();

                //Если не последний экземпляр записи, пропускаем. Или MFN = 0. Не знаю почаму, но такие записи встречаются.
                if( $recordHeader['VERSION'] != 32 || $recordHeader['MFN'] == 0 ){
                    continue;
                }

                //Полная длина записи (    header(уже прочитан ↓) + tagsinfo + tags(или иначе говоря поля записи) )
                //Ну чтобы нагляднее было: hhhhhhhhhhhhhhhhhhhhh iiiiiiiiiiiii tttttttttttttttttttttttttttttttttt
                $recordLength = $recordHeader['MFRL'];

                $recordMfn = $recordHeader['MFN'];

                //Количество тегов(полей)
                $recordTagsCount = $recordHeader['NVF'];

                //Длина информации о тегах
                $recordTagsCount = $recordTagsCount * 12;

                //Проверяем фильтр MFN
                if( !$this->checkFilter( 'mfn', $recordMfn ) ){
                    continue;
                }

                //Сохраняем текущую позицию, она нам еще пригодится
                $headerEndPos = $this->mst_buffer->position();

                //                                                             ↓
                //Ну чтобы нагляднее было: hhhhhhhhhhhhhhhhhhhhh iiiiiiiiiiiii tttttttttttttttttttttttttttttttttt
                $this->mst_buffer->setPosition( $headerEndPos + $recordTagsCount );

                //                                                                                              ↓
                //Ну чтобы нагляднее было: hhhhhhhhhhhhhhhhhhhhh iiiiiiiiiiiii tttttttttttttttttttttttttttttttttt
                $data = $this->mst_buffer->getString( $recordLength - $recordTagsCount - 32);
                $guid = substr($data, 1, 36 );

                //Проверяем фильтр GUID
                if( !$this->checkFilter( 'guid', $guid ) ){
                    continue;
                }
                //Хотя если честно нужны ли они вообще эти фильтры?

                //Возвращаемся на сохраненную позицию, чтобы достать инфо о тегах
                //Осталось только еще инфо о тегах извлечь      ↓
                //Ну чтобы нагляднее было: hhhhhhhhhhhhhhhhhhhhh iiiiiiiiiiiii tttttttttttttttttttttttttttttttttt
                //Я сначала достал tagdata, а только потом [tag,pos,len] чтобы в одном цикле сразу распарсить все
                $this->mst_buffer->setPosition( $headerEndPos );

                $tags = [];

                while( $this->mst_buffer->position() < $headerEndPos + $recordTagsCount ){
                    $TAG = (string) $this->mst_buffer->getUnsignedInt();
                    $POS = (int) $this->mst_buffer->getUnsignedInt();
                    $LEN = (int) $this->mst_buffer->getUnsignedInt();

                    $str = substr($data, $POS, $LEN);

                    $mathes = null;
                    preg_match_all('/\\^(.)([^\\^]*)/', $str, $matches);

                    if( count($matches[0]) === 0 ){
                        $tags[$TAG][]['_'] = $str;
                    }else{
                        $tag_a = [];
                        for($l=0; $l<count($matches[1]);$l++){
                            if( $matches[2][$l] === '' && $this->skipEmptyValues ){
                                continue;
                            }
                            $tag_a[$matches[1][$l]] = $matches[2][$l];
                        }
                        $tags[$TAG][] = $tag_a;
                    }
                }

                //Дата в ирбисе хранится в формате YYYYMMDD. HH:MM:SS добавлю позже если будет такая необходимость.
                $created_at = 0;
                $modified_at = 0;
                if( array_key_exists(907, $tags) && array_key_exists('A', $tags[907][0]) ){
                    $matches = null;
                    preg_match('/\d{8}/', $tags[907][0]['A'], $matches);
                    if( count( $matches ) ){
                        $created_at = (int) $matches[0];
                    }
                    if( count( $tags[907] ) == 1 ){
                        $modified_at = $created_at;
                    }else{
                        $lastDate = array_pop($tags[907]);
                        if( array_key_exists('A', $lastDate) ){
                            $matches = null;
                            preg_match('/\d{8}/', $lastDate['A'], $matches);
                            if( count( $matches ) ){
                                $modified_at = (int) $matches[0];
                            }
                        }
                    }
                }

                //По умолчанию дата в формате YYYYMMDD переводится в таймштамп если удалось достать дату из записи. Если указано обратное, то оставляем as is.
                if( $this->datesAsTimestamps && $created_at != 0 ){
                    $created_at_dateObject = date_create_from_format( 'Ymd', (string)$created_at );
                    $cteated_at_ts = $created_at_dateObject->getTimestamp();
                    $created_at = $cteated_at_ts ? $cteated_at_ts : $created_at;

                    $modified_at_dateObject = date_create_from_format( 'Ymd', (string)$modified_at );
                    $modified_at_ts = $modified_at_dateObject->getTimestamp();
                    $modified_at = $modified_at_ts ? $modified_at_ts : $modified_at;
                }

                if( !$this->checkFilter( 'created_at', $created_at ) ){
                    continue;
                }
                if( !$this->checkFilter( 'modified_at', $modified_at ) ){
                    continue;
                }

                $data = [
                    'db' => $db[2],
                    'mfn' => $recordMfn,
                    'guid' => $guid,
                    'fields' => $tags,
                    'created_at' => $created_at,
                    'modified_at' => $modified_at
                ];

                call_user_func($this->iterator, $data);

                if( isset( $this->writterSql ) ){
                    $this->writterSql->addRecord($data);
                }
            //eow
            }
        //eof
        }
        if( isset( $this->writterSql ) ){
            $this->writterSql->dumpLeftovers();
        }
    }
}