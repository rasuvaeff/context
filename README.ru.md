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

## Требования

- PHP 8.3+
- Без runtime-зависимостей

## Установка

```bash
composer require rasuvaeff/context
```

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
```

Дедлайн потомка никогда не позже родительского: `withTimeout()` и
`withDeadline()` берут более ранний из двух. Нулевой или отрицательный таймаут
даёт немедленный дедлайн; слишком большой насыщается до `PHP_INT_MAX`, а не
переполняется.

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
распространяется на всех потомков.

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

### Тестирование

Подставьте `TimeSource`, чтобы управлять дедлайнами детерминированно:

```php
$clock = new FakeTimeSource();               // ваш TimeSource, возвращающий nowNs()
[$context] = Context::background($clock)->withTimeout(1.0);
$clock->advanceNs(2_000_000_000);
$context->isExpired();                        // true
```

### Публичный API

| Класс | Описание |
|---|---|
| `Context` | Неизменяемое представление: `background()`, `withCancel()`, `withTimeout()`, `withDeadline()`, `withValue()`, `value()`, `assertActive()`, `is*()`, `remainingMs()`, `deadlineNs()`, `onTerminate()` |
| `ContextController` | Владелец ветки: `cancel(?Throwable $reason = null)` |
| `TimeSource` | Шов монотонных часов (`nowNs(): int`) для детерминированных тестов |
| `HrTimeSource` | Продакшен-реализация на `hrtime()` |
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
контекст.

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
