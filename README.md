# Package connector #

Это набор вспомогательных классов для Cotonti обеспечивающий интеграцию Cotonti с пакетами установленными в проект через [Composer](https://getcomposer.org/) (менеджер зависимостей для PHP). Основным из набора для использования является класс `PackageConnector`.

> Если кто не в курсе, скажу пару слов о Composer. Это простой и удобный менеджер пакетов для PHP, который позволяет буквально «в 2 клика» установить нужный компонент в свой проект. Причем компонентом (или пакетом в терминах Composer) может выступать как PHP библиотека, так и какой-либо веб-компонент (типа jQuery или Bootstrap).

> [Здесь и далее цитатами буду выделать менее значимый текст, предполагая, что читатель уже, в некотором объеме, знаком с темой.]

## Назначение и функции ##

Для чего нужен класс `PackageConnector`? Для удобной интеграции пакетов, установленных через Composer, в Cotonti. 

В первую очередь Package Connector позволяет использовать автоматический загрузчик для установленных компонент. Это позволяет сразу приступить к использованию установленной PHP библиотеки, без головной боли с указанием путей к ней и включением директив `require` или `include`.

Кроме автозагрузчика, имеется интерфейс доступа к информации об установленных в проект пакетах. Можно получить информацию о версии, зависимостях (и другую информацию предоставляемую в пакетом Composer).

### Технические детали ###

> Composer для своей работы использует файл `composer.json`, в котором перечисляются пакеты необходимые в проекте, и базовые настройки. На основании этого файла происходит установка пакетов в проект. После первичной установки Composer формирует файл `composer.lock`, в котором описана информация об установленных пакетах, их версиях и зависимостях.
Также при установке любого пакета в проект, Composer формирует файл автозагрузки. Это PHP файл, который описывает правила автозагрузки установленных PHP компонент. Т.е. классы таких компонент будут автоматически загружены, при первом их использовании в проекте.

PackageConnector использует данные из файлов `composer.json` и `composer.lock`, которые загружаются при инициализации класса, для определения места установки пакетов и нахождения файла автозагрузки. 

#### Начало работы ####
Для работы с коннектором надо создать экземпляр класса:
```php
$cot_packages = new PackageConnector();
```
А затем его проинициализировать:
```php
$cot_packages->setup();
```
*По умолчанию файлы настроек (composer.\*) ищутся в корневом каталоге сайта, где и должны быть. Тем не менее, при инициализации можно указать другой путь.*

После инициализации (или ре-инициализации) Package Connector (при условии нахождения и успешной загрузки данных) можно приступать к дальнейшему использованию.

Для использования (подключения) файла автозагрузки Composer существует отдельный метод:
```php
$cot_packages->connectAutoloader();
```

#### Получение данных о пакетах ####

Если в проекте установлены какие-либо Composer пакеты, то через коннектор можно так же получить данные о них. Для этого используется «встроенный» класс `InstalledPackageInfo`, доступ к экземпляру которого осуществляется через сам Коннектор:
```php
// получаем ссылку на объект класса `InstalledPackageInfo`
$package = $cot_packages->package('package_name'); 
```
Метод `package` возвращает ссылку на объект с данными, поэтому для вызова методов встроенного класса можно использовать цепочку:
```php
// получаем массив с данными о пакете
$bs_info = $cot_packages->package('bootstrap')->getInfo(); 
```

Одной из особенностей Коннектора (точнее класса `InstalledPackageInfo`) является возможность обращения к пакету по короткому имени.

> Composer использует для однозначной идентификации пакетов правило именования по формату `Vendor-name/Package-name`, где Vendor-name имя поставщика, а Package-name имя пакета. Таким образом Composer позволяет различным поставщикам использовать одинаковые имена для своих пакетов.

Package Connector предоставляет механизм доступа к информации о пакете по его  короткому имени (Package-name). В этом случае Коннектор самостоятельно позаботится о том, чтобы восстановить его до полного имени (получить полное имя пакета из списка установленных). 

Такой подход, во многом, упрощает разработку, т.к. в реальном проекте не так часто возникает ситуация, когда установлены 2 пакета с одим именем.

Кроме того, всегда можно обратиться к пакету по полному имени:
```php
$jq_version = $cot_packages->package('components/jquery')->getVersion();
```

#### Фильтрация по типу пакета ####

Если в системе несколько пакетов с одинаковыми («короткими») именами или нужно найти какой-то определенный тип пакетов — можно прибегнуть к фильтрации. Для этого, есть возможность задать дополнительный параметр  — фильтр по типу пакета:
```php
// ищем пакет с кортким именем `bootstrap` и типом `component`
$bs = $cot_packages->package('bootstrap', 'component');
```

#### Обработка ошибок ####

Класс разработан максимально независимым от внутренних механизмов Cotonti и Composer, поэтому для обработки ошибок `PackageConnector` использует внутри себя  вспомогательные классы `LastError` и `LastErrorStack`.

Большинство методов коннектора написаны так, что в качестве положительного результата работы возвращают данные (или значение TRUE), и возвращают пустой (NULL) результат (или FALSE) в случае неудачного исполнения. Описание ошибки, при этом, сохраняется во внутреннем буфере.
Соответственно внешняя обработка ошибок может быть произведена путем проверки результата и запроса текста ошибки через метод `getLastError`, в случае таковой:
```php
if ($cot_packages->setup())
{
	// данные найдены, можем работать с Коннектором
}
else
{
	// выводим сообщения о всех ошибках 
	while ($error_message = $cot_packages->getLastError()) 
	{
		cot_error($error_message);
	}
}
```

Массив стандартных сообщений об ошибках на английском языке, содержится в статическом свойстве класса `$defaultMsg`. При необходимости стандартные сообщения можно заменить на собственные (например для нужд локализации). Для этого используется метод `messagesInit()`, которому передается массив с сообщениями по образцу массива `$defaultMsg`:
```php
$i10n_msg_ru = array(
	'no_lock' => 'Файл composer.lock не найден.',
	...
);
$i10n_msg_ru && $cot_packages->messagesInit($i10n_msg_ru);
```

### Дальнейшая разработка ###

Package Connector находится в стадии активной разработки и предлагаемые им методы и свойства могут быть изменены.
Для минимизации ошибок при разработке используется [PHPUnit](https://phpunit.de/). Тесты размещены в файле `PackageConnectorTest.php`.
Запуск тестов (при условии установленного `PHPUnit`) производится командой (из ):
```
php phpunit . 
```
Запуск в формате **указание текущего каталога**, а не конкретного файла, **принципиально**. В противном случае будут проведены тесты только для класса PackageConnector, а не для всего набора классов.


## Тестовая реализация ##

Несмотря на то, что файл класса-расширения может быть с легкостью интегрирован в ядро системы с минимальными доработками для целей текущего тестирования на рабочих проектах класс PackageConnector поставляется в составе Расширения (плагина) [«package_connector»](https://github.com/macik/cot-package_connector). Плагин берет на себя ту небольшую часть кода (см. файл `package_connector.input.php`), которая отвечает за инициализацию класса, вызов автозагрузчика и кэширование самого объекта.

В системе создается глобальная переменная `$cot_packages` — экземпляр класса `PackageConnector`:
```php
$cot_packages = new PackageConnector();
```
Она же кэшируется для ускорения инициализации:
```php
$cache && $cache->db->store('cot_packages', $cot_packages, 'system');
```

> Cotonti при очередном запуске восстанавливает системные переменные из кеша автоматически. Поэтому делать это в ручную нет необходимости.

Внутренний механизм коннектора устроен так, что перед сохранением в кеш, для экономии, неиспользуемые внутренние данные объекта удаляются. И соответственно, при восстановлении из кеша будут восстановлены сохраненные в нем данные, плюс сгенерированы те, которые потребуются для дальнейшей работы.

Для исключения излишних процедур инициализации (что требует загрузки и обработки файлов, пусть и небольших), класс имеет метод проверки состояния исходных файлов `stateChanged()`. Если файлы изменились, необходимо повторно провести их обработку (setup):
```php
if ($cot_packages->stateChanged()) $cot_packages->setup();
```

Плагин не требует дополнительных настроек.