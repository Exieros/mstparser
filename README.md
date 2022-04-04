# Скрипт позволяет в потоке прочитать *.MST файл базы данных Ирбис64.

## Пример использования:
```php
require_once './Mstparser.php';

$parser = new \exieros\mstparser\Mstparser();

$parser
->setPath("\\\Server_odb\irbis64\datai\OKIO\OKIO.MST")
->setIterator(function($e){
	var_dump($e['guid']);
})
->addFilter(['modified_at', '>', 1648987200])
->addFilter(['guid', '=', '545AE69B-EB0B-4796-9470-DB223BBBC87F'])
->start();
 ```
## Небольшая справка
``` ->setPath (Строка)```
Путь до *.MST файла базы данных. В примере выше используется сетевая папка.

``` ->setIterator (Функция)```
Callback функция, которая будет на вход получать записи при условии если они прошли фильтрацию

``` ->addFilter (Массив) ``` [
	'guid, mfn, created_at, modified_at', 
	'=(only guid), <, >' , 
	'string for guid, int for mfn and timestamps'
])
 
``` ->start() ```
Запуск работы парсера.
 
## На слабеньком компьютере через сетевую папку итерация 40.000 записей занимает порядка 6 секунд. По-хорошему нужно бы профилировать и оптимизировать узкие места.
upd: Если читать тот же .mst с локального файла, тогда процесс занимает ~3 секунды. Все зависит от пропускной способности сети. Потому будет лучше если парсер будет работать на той же машине, что и сервер ирбис.

![Структура MST](https://github.com/Exieros/mstparser/raw/main/scheme.svg)
 
 
 
