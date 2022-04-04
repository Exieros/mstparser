<?php
namespace exieros\mstparser;

require_once './vendor/autoload.php';

use Exception;
use Nelexa\Buffer\FileBuffer;

class Mstparser 
{
    private string $mst_path;
    private array $filters;
    private $iterator;
    


    public function __construct()
    {
        $this->filters = [];

        echo __CLASS__;            
    }

    public function setIterator(callable $iterator): self{
        $this->iterator = $iterator;
        return $this;
    }

    public function setPath(string $path): self{
        if( !file_exists($path) || pathinfo($path, PATHINFO_EXTENSION) != 'MST' ){
            throw new Exception('*.MST file not found or the specified file is not *.MST');
        }
        $this->mst_path = $path;
        return $this;
    }

    public function addFilter(array $filter): self{
        array_push($this->filters, $filter);
        return $this;
    }

    public function start(){
        $mst_buffer = new FileBuffer($this->mst_path);
        $mst_buffer->setReadOnly(true);

        $db_header = [
            'NXTMFN' => $mst_buffer->getLong(),
            'NXT_LOW' => $mst_buffer->getUnsignedInt(),
            'NXT_HIGH' => $mst_buffer->getUnsignedInt(),
            'MFTYPE' => $mst_buffer->getUnsignedInt(),
            'RECCNT' => $mst_buffer->getUnsignedInt(),
            'MFCXX1' => $mst_buffer->getUnsignedInt(),
            'MFCXX2' => $mst_buffer->getUnsignedInt(),
            'MFCXX3' => $mst_buffer->getUnsignedInt()
        ];

        while($mst_buffer->position() !== $db_header['NXT_LOW']){
            /*
                Инфо об отдельной записи. Длина 32 байта.
            */
            $record_info = [
                'MFN' => $mst_buffer->getUnsignedInt(),
                'MFRL' => $mst_buffer->getUnsignedInt(), // Длина записи
                'MFB_LOW' => $mst_buffer->getUnsignedInt(),
                'MFB_HIGH' => $mst_buffer->getUnsignedInt(),
                'BASE' => $mst_buffer->getUnsignedInt(),
                'NVF' => $mst_buffer->getUnsignedInt(), // Количество инфосекций о тегах
                'STATUS' => $mst_buffer->getUnsignedInt(),
                'STATUS2' => $mst_buffer->getUnsignedInt()
            ];

            if( $record_info['STATUS2'] !== 32 ){
                $mst_buffer->setPosition( $mst_buffer->position() + ( $record_info['MFRL'] - 32 ) );
                continue;
            }

            $tags = [];

            for($t=1; $t<=$record_info['NVF']; $t++){
                $TAG = (string) $mst_buffer->getUnsignedInt();
                $POS = (int) $mst_buffer->getUnsignedInt();
                $LEN = (int) $mst_buffer->getUnsignedInt();

                if( array_key_exists($TAG, $tags) ){
                    array_push($tags[$TAG], [
                        'POS' => $POS,
                        'LEN' => $LEN
                    ]);
                }else{
                    $tags[$TAG] = [[
                        'POS' => $POS,
                        'LEN' => $LEN
                    ]];
                }
            }

            $section__tags_info_size = $record_info['NVF'] * 12;
            $data = $mst_buffer->getString($record_info['MFRL'] - $section__tags_info_size - 32);

            $mfn = $record_info['MFN'];
            $guid = substr($data, 1, 36 );

            foreach ($this->filters as $key => $filter) {
                if( count($filter) !== 3 ){
                    continue;
                }

                if( $filter[0] == 'guid' && $filter[1] == '=' ){
                    if( $guid != $filter[2] ){
                        continue(2);
                    }
                }

                if( $filter[0] == 'mfn' && $filter[1] == '=' ){
                    if( $mfn != $filter[2] ){
                        continue(2);
                    }
                }

                if( $filter[0] == 'mfn' && $filter[1] == '>' ){
                    if( $mfn < $filter[2] ){
                        continue(2);
                    }
                }

                if( $filter[0] == 'mfn' && $filter[1] == '<' ){
                    if( $mfn > $filter[2] ){
                        continue(2);
                    }
                }
            }

            foreach ($tags as &$tag_a) {
                $tag_a = array_map(function($t) use ($data){
                    $str = substr($data, $t['POS'], $t['LEN']);
                    
                    preg_match_all('/\^./', $str, $m);

                    if( count($m[0]) === 0 ){
                        $t['_'] = $str;
                    }else{
                        $matches = null;
                        $rv = preg_match_all('/\\^(.)([^\\^]*)/', $str, $matches); 
                        for($l=0; $l<count($matches[1]);$l++){
                            if($matches[2][$l] === ''){
                                continue;
                            }
                            $t[$matches[1][$l]] = $matches[2][$l];
                        }
                    }
                    unset($t['POS']);
                    unset($t['LEN']);
                    return $t;
                }, $tag_a);
            }
            unset($tag_a);

            $timestamps = $this->getCreatedModifiedTimestamps($tags);

            $data = [
                'id' => $mfn,
                'guid' => $guid,
                'fields' => $tags,
                'created_at' => $timestamps['created_at'],
                'modified_at' => $timestamps['modified_at']
            ];

            foreach ($this->filters as $key => $filter) {
                if( count($filter) !== 3 ){
                    continue;
                }

                if( $filter[0] == 'created_at' && $filter[1] == '=' ){
                    if( $data['created_at'] != $filter[2] ){
                        continue(2);
                    }
                }

                if( $filter[0] == 'created_at' && $filter[1] == '>' ){
                    if( $data['created_at'] < $filter[2] ){
                        continue(2);
                    }
                }

                if( $filter[0] == 'created_at' && $filter[1] == '<' ){
                    if( $data['created_at'] > $filter[2] ){
                        continue(2);
                    }
                }

                if( $filter[0] == 'modified_at' && $filter[1] == '=' ){
                    if( $data['modified_at'] != $filter[2] ){
                        continue(2);
                    }
                }

                if( $filter[0] == 'modified_at' && $filter[1] == '>' ){
                    if( $data['modified_at'] < $filter[2] ){
                        continue(2);
                    }
                }

                if( $filter[0] == 'modified_at' && $filter[1] == '<' ){
                    if( $data['modified_at'] > $filter[2] ){
                        continue(2);
                    }
                }
            }

            call_user_func($this->iterator, $data);
        }
    }

    public function getCreatedModifiedTimestamps(array $tags): array{
        $created_at_ts = 0;
        $modified_at_ts = 0;
        if( array_key_exists( '907', $tags ) ){
            if( count( $tags['907'] ) == 0 ){
                return [
                    'created_at' => $created_at_ts,
                    'modified_at' => $modified_at_ts
                ];
            }
            $created_at = $tags['907'][0];
            if( array_key_exists( 'A', $created_at ) ){
                $date = $created_at['A'];

                if( array_key_exists( 'X', $created_at ) ){
                    $matches = null;
                    preg_match('/^\\d{2}:\\d{2}:\\d{2}/', $created_at['X'], $matches);
                    $time = $matches ? $matches[0] : '00:00:00';
                }else{
                    $time = '00:00:00';
                }

                $parsed_date = date_create_from_format( 'Ymd G:i:s', $date . ' ' . $time );
                $created_at_ts = $parsed_date->getTimestamp() ? $parsed_date->getTimestamp() : 0;
            }
            $modified_at = array_pop($tags['907']);
            if( array_key_exists( 'A', $modified_at ) ){
                $date = $modified_at['A'];

                if( array_key_exists( 'X', $modified_at ) ){
                    $matches = null;
                    preg_match('/^\\d{2}:\\d{2}:\\d{2}/', $modified_at['X'], $matches);
                    $time = $matches ? $matches[0] : '00:00:00';
                }else{
                    $time = '00:00:00';
                }
                $parsed_date = date_create_from_format( 'Ymd G:i:s', $date . ' ' . $time );
                $modified_at_ts = $parsed_date->getTimestamp() ? $parsed_date->getTimestamp() : 0;

            }
            return [
                'created_at' => $created_at_ts,
                'modified_at' => $modified_at_ts
            ];
        }
        return [
            'created_at' => $created_at_ts,
            'modified_at' => $modified_at_ts
        ];
    }

}