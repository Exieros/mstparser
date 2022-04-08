# Скрипт позволяет в потоке прочитать *.MST файл базы данных Ирбис64.

## Пример использования:
```php
require_once './Mstparser.php';

$parser = new \exieros\mstparser\Mstparser();

$parser
->setDatabasesPath( '\\\Server_odb\irbis64\datai' )
->addDatabase( 'OKIO' )
->setDatesAsTimestamps( false )
->addFilter( ['created_at', '>', 20220403] )
->setIterator(function($e){
    echo 'MFN: ', $e['mfn'], '<br>';
    echo 'created_at: ', $e['created_at'], '<br>';
    echo 'modified_at: ', $e['modified_at'], '<br>';
})
->start();
 ```
## Небольшая справка
### ->setDatabasesPath (Строка)
Путь до директории с база данных. В примере выше используется сетевая папка. Однако крайне не рекомендую этого делать. Разница между локальным чтением и по сети на порядки. Разумеется зависит еще пропускной способности сети. Потому лучше запускать скрипт на той же машине, что и базы данных Ирбис64. По умолчанию это папка ...\irbis64\datai

### ->addDatabase (Строка)
Добавить базу данных в обработку.
Если вы указали setDatabasesPath("C:\irbis64\datai") и addDatabase("OKIO"),
то по пути C:\irbis64\datai\OKIO должны быть доступны два файла: OKIO.MST и OKIO.xrf

### skipEmptyValues (Bool)(По умолчанию true)
Добавлять ли пустые строки в результатирующую выборку полей. По умолчанию пустые строки пропускаются.

### ->setDatesAsTimestamps (Bool)(По умолчанию true)
Выводить даты as is YYYYMMDD или переводить их в таймштамп

### ->setIterator (Функция)
Callback функция, которая будет на вход получать записи при условии если они прошли фильтрацию

### ->addFilter (Массив ['guid, mfn, created_at, modified_at', '=(only guid), <, >' , 'string for guid, int for mfn and timestamps'])
Добавить фильтр. Фильтр Умножающий! Тоесть если одно из условий не выполняется, ничего не попадет в выдачу.
Примеры:
```php
//Записи дата добавления в ирбис не ранее 2022.04.03
->addFilter( ['created_at', '>', 20220403] )
//Записи дата последней модификации не ранее 2022.04.03 (Однако сильно прямо полагаться на эти даты не стоит, они не гарантируют ничего ввиду особенностей работы ирбис)
->addFilter( ['modified_at', '>', 20220403] )
//Записи с mfn от 1 до 99
->addFilter( ['mfn', '<', 100] )
/*Запись c определенным guid
!Фильтр вида ['guid', '=', '{E05F04F2-C8D2-44B7-B528-471D31375F8B}'] не вернет ничего. Именно в таком формате они почему-то хранятся в ирбис.....
*/
->addFilter( ['guid', '=', 'E05F04F2-C8D2-44B7-B528-471D31375F8B'] )
 ```

### ->dumpToSQL(String:pathToSql, Bool:addDropCreateLines, Bool:deleteFileIfExist, Int:chunkSize)
Если вызвать этот метод, строки которые пройдут фильтрацию будут добавлены в дамп .sql
pathToSql - Путь куда сохранять дамп
addDropCreateLines - Добавить ли Drop и Create конструкции для таблиц
```sql
DROP TABLE IF EXISTS `records`;
DROP TABLE IF EXISTS `fields`;

CREATE TABLE `fields` (
    `id` bigint NOT NULL AUTO_INCREMENT,
    `num` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `subkey` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `record_id` int NOT NULL,
    `value` text CHARSET utf8mb4,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `records` (
    `id` int NOT NULL AUTO_INCREMENT,
    `guid` text,
    `created_at_irbis` int NOT NULL DEFAULT '0',
    `modified_at_irbis` int NOT NULL DEFAULT '0',
    `dbname` text,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
deleteFileIfExist - Удалить ли уже существующий .sql файл если он будет найден по указанному пути
chunkSize - Максимальный размер пакета Insert. Как показывает пратика чем больше, тем быстрее происходит дамп и импорт. Однако устанавливать стоит в разумных пределах.

Пример использования:
```php
require_once './Mstparser.php';

$parser = new \exieros\mstparser\Mstparser();

$parser
->setDatabasesPath( '\\\Server_odb\irbis64\datai' )
->addDatabase( 'OKIO' )
->addDatabase( 'BIBL' )
->setDatesAsTimestamps( false )
->setIterator(function($e){

})
->dumpToSQL('C:\IST\www\test.sql', true, true , 10000)
->start();
```
Разумеется импортировать большой дамп через веб-морду не самая лучшая идея потому через консоль это можно сделать следующими командами:
```cmd
cmd> ./mysql --user=user --password=password --default-character-set=utf8mb4
mysql> use database_name
mysql> source path_to_sql_dump
```
Функция экспериментальная.

### ->start()
Запуск работы парсера.

На слабом компьютере с дохлым hhd база данных на 40000 записей читается 3-4 секунды.