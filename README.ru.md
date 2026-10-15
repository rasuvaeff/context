# rasuvaeff/context

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/context/v)](https://packagist.org/packages/rasuvaeff/context)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/context/downloads)](https://packagist.org/packages/rasuvaeff/context)
[![Build](https://github.com/rasuvaeff/context/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/context/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/context/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/context/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/context/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/context/php)](https://packagist.org/packages/rasuvaeff/context)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Дедлайн, отмена и значения в рамках запроса для PHP

> Пользуетесь AI-ассистентом? В [llms.txt](llms.txt) — компактный справочник по API, который можно передать модели.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills)
> дополнительно получают agent-скилл этого пакета в `.agents/skills/`
> автоматически при установке.

## Требования

- PHP 8.3+
- Без runtime-зависимостей

## Установка

```bash
composer require rasuvaeff/context
```

## Ограничения

Контекст **останавливает работу, а не прерывает её.** Он гарантирует две
вещи: код, проверяющий контекст, не *начнёт* шаг после исчерпания бюджета, и
вызывающий не будет *ждать* дольше бюджета, если транспорт переводит
`remainingMs()` в собственный таймаут. Прервать уже идущий вызов можно только
там, где это умеет транспорт:

| Транспорт | Бюджет → таймаут | Прервать идущий вызов |
|---|---|---|
| Ретраи, конвейеры, батчи, собственные циклы | — | Да: `assertActive()` между шагами и попытками |
| Потребители очередей | TTR сообщения → `withTimeout()` | Да, в собственном цикле |
| HTTP через Guzzle | Per-request `timeout` = остаток бюджета ([`rasuvaeff/context-http`](https://github.com/rasuvaeff/context-http)) | Нет для синхронного вызова — он вернётся по собственному таймауту |
| HTTP через произвольный PSR-18 клиент | Нет: у PSR-18 нет per-request опций — настройте клиент | Нет |
| ClickHouse по HTTP | `max_execution_time` ([`rasuvaeff/clickhouse-toolkit`](https://github.com/rasuvaeff/clickhouse-toolkit)) | Сервер сам соблюдает `max_execution_time` |
| MySQL / PostgreSQL через PDO | Нет: у PDO нет таймаута на запрос (только на подключение) | Только вне основного соединения (`KILL QUERY`, `pg_cancel_backend()`) — не реализовано |
| Redis | Только уменьшением read timeout клиента | Нет для блокирующих команд (`BLPOP`) |

Дедлайны **ленивые**: фонового таймера нет. Истёкший дедлайн обнаруживает
следующий вызов `assertActive()`, `remainingMs()`, `isTerminated()` или
`isExpired()`; если контекст никто не проверяет, его `onTerminate()`-listener'ы
не запускаются никогда. Проверяйте перед каждым шагом, а не один раз в начале.

`cancel()` запускает listener'ы синхронно, в вызывающем коде.
Однопоточный PHP-запрос, заблокированный внутри вызова, не может одновременно
отменить сам себя, поэтому listener полезен только после возврата управления —
по таймауту транспорта, между шагами или под fiber/event loop. Из обработчика
сигнала используйте `requestCancel()`: он ничего не исполняет до следующей
проверки.

## Использование

`Context` — неизменяемое представление только для чтения, которое передаётся
вниз по графу вызовов: дедлайн, сигнал отмены и значения в рамках запроса.
Владелец отменяемой ветки держит соответствующий `ContextController`. Отмена
кооперативная: код проверяет контекст между шагами, посреди вызова ничего не
прерывается.

### Создание и производные контексты

```php
use Rasuvaeff\Context\Context;

$root = Context::background();                       // сам по себе не завершается

[$request, $requestController] = $root->withTimeout(3.0);   // секунды
[$step, $stepController] = $request->withCancel();          // отменяемая ветка
[$capped] = $request->withDeadline($absoluteNs);            // монотонные наносекунды hrtime()

$traced = $request->withValue(RequestId::class, new RequestId('r-42'));
$traced->value(RequestId::class)?->value;                    // 'r-42' (выигрывает ближайший предок)

[$work] = $request->withReserve(0.2);                        // дедлайн родителя минус 200 мс
$cleanup = $request->withoutCancel();                        // только значения: переживает запрос
[$job, $jobController] = $message->joinedWith($worker);      // завершается вместе с любым из двух
```

Дедлайн потомка никогда не позже родительского: `withTimeout()` и
`withDeadline()` берут более ранний из двух. Нулевой или отрицательный таймаут
даёт немедленный дедлайн; слишком большой насыщается до `PHP_INT_MAX`, а не
переполняется.

- **`withReserve($seconds)`** оставляет время на действия после возврата
  нижних слоёв — ответить 504, сделать NACK сообщению, сохранить прогресс. Без
  дедлайна у родителя это `withCancel()`; резерв, покрывающий весь бюджет,
  даёт уже истёкший контекст.
- **`withoutCancel()`** сохраняет значения, но отбрасывает дедлайн и отмену:
  для аудита, компенсаций и логирования ошибок в `finally` после окончания
  запроса. Бюджета у него нет — ограничьте его:
  `[$cleanup] = $request->withoutCancel()->withTimeout(2.0)`.
- **`joinedWith($other)`** подчиняется двум независимым источникам:
  shutdown-контексту воркера и контексту сообщения, пришедшему извне.
  Выигрывает первая причина, дедлайн — более ранний, значения берутся только
  из `$this`. Если оба из одного дерева — деривируйте:
  `$worker->withTimeout($ttr)` уже подчиняется обоим.

### Проверки

```php
$request->assertActive();        // бросает ContextCancelledException / ContextExpiredException
$request->isTerminated();        // отменён или истёк
$request->isCancelled();         // явная отмена
$request->isExpired();           // дедлайн прошёл
$request->remainingMs();         // null без дедлайна; >= 1.0 пока активен, 0.0 после истечения
```

Дедлайны считаются по монотонному `hrtime()` и обнаруживаются лениво:
истёкший дедлайн замечает следующий вызов `assertActive()`, `remainingMs()`,
`isTerminated()` или `isExpired()`. Если дедлайн принадлежит предку,
завершаются предок и всё его поддерево. Отмена через контроллер сразу
распространяется на всех потомков. Одна проверка стоит около 0,2 мкс.

```php
$request->sleep(0.5);            // не дольше дедлайна: бросает, если он наступил раньше

foreach ($request->iterate($rows) as $key => $row) {
    process($row);               // проверка перед взятием каждого элемента
}
```

`sleep()` бросает `ContextExpiredException`, если дедлайн наступил раньше
конца сна, и `ContextCancelledException`, если за это время обработчик
сигнала запросил отмену. `iterate()` сохраняет ключи и проверяет контекст
**до взятия** следующего элемента, поэтому генератор-источник (постраничный
запрос) не продвигается после завершения контекста; для очень дешёвых
элементов передайте `every: 100`.

### Отмена и освобождение ресурсов

```php
$unsubscribe = $request->onTerminate(static function (TerminationCause $cause): void {
    // освободить ресурс, принадлежащий операции
});

$unsubscribe();                  // ресурс освобождён раньше — слушатель больше не нужен
$requestController->cancel($reason);   // $reason станет previous у исключения
```

`onTerminate()` вызывает слушателя один раз при завершении контекста (сразу,
если он уже завершён) и возвращает замыкание, которое снимает регистрацию.
Завершённый контекст отсоединяется от родителя и отпускает своих слушателей,
так что долгоживущий родитель не копит потомков по одному на запрос.
Контроллер, которым владеете, всегда отменяйте в `finally`.

Бросающий слушатель не останавливает остальных и каскад; его исключение
пробрасывается после каскада — из `cancel()` или из проверки, обнаружившей
завершение.

**Из обработчика сигнала никогда не вызывайте `cancel()`** — он запустит
слушателей в произвольной точке прерванного кода. `requestCancel()` только
записывает причину; следующая проверка контекста (или потомка) завершает его,
так что слушатели работают, а их ошибки всплывают, в обычном потоке кода:

```php
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static fn() => $workerController->requestCancel(new \RuntimeException('SIGTERM')));
```

`rasuvaeff/context-cli` оборачивает это с восстановлением обработчиков и
принудительным выходом по второму сигналу.

### Воркеры очередей

Создавайте контекст на каждое сообщение с TTR задачи в роли дедлайна,
проверяйте его между внешними вызовами и подтверждайте сообщение только после
завершения задачи:

```php
[$jobContext, $jobController] = Context::background()->withTimeout($ttrSeconds);
try {
    foreach ($steps as $step) {
        $jobContext->assertActive();
        $step($jobContext);
    }
    $queue->ack($message);
} catch (ContextExpiredException) {
    $queue->nack($message); // retry или dead-letter решает очередь
} finally {
    $jobController->cancel();
}
```

### Передача через границу процесса

Дедлайн — это монотонные наносекунды текущего процесса, в другом процессе они
ничего не значат. Передавайте оставшийся бюджет и восстанавливайте его на
другой стороне:

```php
// отправитель: HTTP-заголовок или атрибут сообщения очереди
$headers['X-Request-Timeout-Ms'] = (string) (int) $context->remainingMs();

// получатель: не дольше собственного лимита
[$context] = Context::background()->withTimeout(min($limitSeconds, $headerMs / 1000));
```

Входящее значение проверяйте (целое число в ограниченном диапазоне), прежде
чем ему доверять. Для PSR-15/PSR-18 это реализует `rasuvaeff/context-http`.

Чтобы передать и значения запроса, используйте `WireCodec`. Передаются только
классы, реализующие `WireValue`, под стабильными алиасами, которые выбираете
вы:

```php
final readonly class TenantId implements WireValue
{
    public function __construct(public string $value) { /* валидация */ }
    public function toWire(): string { return $this->value; }
    public static function fromWire(string $wire): static { return new static($wire); }
}

$codec = new WireCodec(['tenant' => TenantId::class]);

$message->attributes = $codec->encode($context);    // ['budget_ms' => 790, 'values' => ['tenant' => 'acme']]

[$context, $controller] = $codec->decode($message->attributes, Context::background(), maxBudgetMs: 30_000);
```

`decode()` считает вход недоверенным и никогда на нём не бросает: бюджет
ограничен `maxBudgetMs` и родителем, отсутствующий или некорректный бюджет
заменяется на `maxBudgetMs` (плохой вход никогда не снимает лимит),
неизвестные алиасы отбрасываются, значение, чей `fromWire()` бросил,
отбрасывается (передайте `onInvalid`, чтобы это залогировать). Отмена не
передаётся — это событие, а не состояние.

### Тестирование

Пакет поставляет детерминированные часы в `Rasuvaeff\Context\Testing`:

```php
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Context\Testing\SteppingTimeSource;

$clock = new FakeTimeSource();               // он же Sleeper: sleep() двигает часы
[$context] = Context::background($clock)->withTimeout(1.0);
$clock->advanceMs(2_000);
$context->isExpired();                        // true

$racy = new SteppingTimeSource(600_000_000);  // +0,6 с на каждое чтение
```

`SteppingTimeSource` сдвигает часы между двумя последовательными проверками
внутри одного вызова — так доказывается, что адаптер никогда не действует по
истёкшему бюджету.

### Как писать адаптер

- **Читайте `remainingMs()` до `assertActive()`.** Если читать после проверки,
  дедлайн, прошедший между ними, даёт `0.0` — а `0` для curl, для
  `max_execution_time` в ClickHouse и для `MAX_EXECUTION_TIME` в MySQL
  означает *без таймаута*. Если читать до, прошедшая проверка гарантирует
  минимум 1 мс.
- Ошибку таймаута транспорта превращайте в `ContextExpiredException` с
  исходной в `previous`, если к этому моменту контекст истёк; иначе
  пробрасывайте как есть (таймаут, выставленный не вами).
- Гонку тестируйте через `SteppingTimeSource`.

### Публичный API

| Класс | Описание |
|---|---|
| `Context` | Неизменяемое представление: `background(?TimeSource, ?Sleeper)`, `withCancel()`, `withTimeout()`, `withDeadline()`, `withReserve()`, `withoutCancel()`, `joinedWith()`, `withValue()`, `value()`, `assertActive()`, `is*()`, `remainingMs()`, `deadlineNs()`, `sleep()`, `iterate()`, `onTerminate()` |
| `ContextController` | Владелец ветки: `cancel(?Throwable $reason = null)`, `requestCancel(?Throwable $reason = null)` |
| `TimeSource` | Шов монотонных часов (`nowNs(): int`) для детерминированных тестов |
| `HrTimeSource` | Продакшен-реализация на `hrtime()` |
| `Sleeper` | Шов сна (`sleepNs(int)`) за `sleep()`; может вернуться раньше |
| `UsleepSleeper` | Продакшен-реализация на `usleep()` |
| `WireCodec` | Бюджет и разрешённые значения через границу процесса: `encode()`, `decode()` |
| `WireValue` | Значение, которое может пересечь границу: `toWire()`, `fromWire()` |
| `Testing\FakeTimeSource` | Детерминированные часы и sleeper: `advanceNs()`, `advanceMs()` |
| `Testing\SteppingTimeSource` | Часы, сдвигающиеся на каждом чтении, для тестов гонок |
| `TerminationCause` | Причина завершения (отмена или дедлайн), передаётся слушателям |
| `ContextException` | Базовое исключение контекста (`RuntimeException`) |
| `ContextCancelledException` | Явная отмена, обнаруженная `assertActive()`; причина — `previous` |
| `ContextExpiredException` | Истёкший дедлайн, обнаруженный `assertActive()`; `deadlineNs()`, необязательный `previous` |

## Безопасность

Значения контекста — метаданные в рамках запроса, а не service locator: ключи
— имена классов, значение обязано быть экземпляром своего ключа. Пакет никогда
не прерывает блокирующий системный вызов: транспортные адаптеры должны
переводить оставшийся бюджет в собственные таймауты или регистрировать
слушателя `onTerminate()` для очистки. Бюджет, пришедший из другого процесса,
— недоверенный вход: проверьте и ограничьте его, прежде чем строить из него
контекст. `WireCodec::decode()` делает это и принимает значения только через
`WireValue::fromWire()` из списка разрешённых классов, никогда не через
`unserialize()`: валидируйте в `fromWire()` и бросайте на всём неожиданном.

## Примеры

Исполняемые скрипты — в [examples/](examples/). Примеры должны выполняться без
фатальных ошибок и соответствовать документированному публичному API.

| Скрипт | Что показывает | Нужен сервер? |
|---|---|---|
| `basic.php` | Производные контексты, значения в рамках запроса и отмена | нет |

## Разработка

На хосте нет PHP/Composer — всё через Docker-образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Или через Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` и `make mutation` ставят `pcov` внутри контейнера
`composer:2`: в базовом образе нет драйвера покрытия.

## Лицензия

[BSD-3-Clause](LICENSE.md)
