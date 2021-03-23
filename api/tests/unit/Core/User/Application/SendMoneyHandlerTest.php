<?php

declare(strict_types=1);

namespace MiniPay\Tests\Core\User\Application;

use DateTimeImmutable;
use MiniPay\Core\User\Application\SendMoney;
use MiniPay\Core\User\Application\SendMoneyHandler;
use MiniPay\Core\User\Domain\DefaultUser;
use MiniPay\Core\User\Domain\Event\TransactionCreated;
use MiniPay\Core\User\Domain\Exception\CannotSendMoney;
use MiniPay\Core\User\Domain\Exception\TransactionUnauthorized;
use MiniPay\Core\User\Domain\Exception\UserNotFound;
use MiniPay\Core\User\Domain\StoreKeeperUser;
use MiniPay\Core\User\Domain\User;
use MiniPay\Core\User\Domain\Wallet;
use MiniPay\Core\User\Infrastructure\Persistence\InMemoryUserRepository;
use MiniPay\Framework\DomainEvent\Infrastructure\InMemoryEventStore;
use MiniPay\Framework\Id\Domain\Id;
use MiniPay\Tests\Core\User\Infrastructure\FakeTransactionAuthClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

use function assert;
use function get_class;

class SendMoneyHandlerTest extends TestCase
{
    /**
     * @test
     */
    public function shouldSendMoneyBetweenTwoDefaultUser(): void
    {
        $payerId = Id::fromString('payer-id');
        $payeeId = Id::fromString('payee-id');
        $valueToSend = 50;
        $expectedPayerBalance = 50;
        $expectedPayeeBalance = 150;
        $expectedEvents = TransactionCreated::create(
            $payerId->toString(),
            $payeeId->toString(),
            $valueToSend,
            new DateTimeImmutable()
        );

        $repository = new InMemoryUserRepository([
            $this->createDefaultUser($payerId),
            $this->createDefaultUser($payeeId),
        ]);
        $eventBus = new MessageBus();
        $eventStore = new InMemoryEventStore(new Serializer([new ObjectNormalizer()], [new JsonEncoder()]));
        $transactionAuthClient = new FakeTransactionAuthClient(true);
        $handler = new SendMoneyHandler($eventBus, $eventStore, $repository, $transactionAuthClient);

        $command = new SendMoney(
            $payerId->toString(),
            $payeeId->toString(),
            $valueToSend
        );

        $handler($command);

        $foundPayer = $repository->findOneByIdOrNull($payerId);
        assert($foundPayer instanceof User);
        $foundPayee = $repository->findOneByIdOrNull($payeeId);
        assert($foundPayee instanceof User);

        $this->assertEquals($expectedPayerBalance, $foundPayer->balance());
        $this->assertEquals($expectedPayeeBalance, $foundPayee->balance());

        $events = $eventStore->allStoredEvents();
        $this->assertCount(1, $events);
        $this->assertEquals(get_class($expectedEvents), $events[0]->typeName());
        $this->assertStringContainsString($foundPayer->id()->toString(), $events[0]->body());
        $this->assertStringContainsString($foundPayee->id()->toString(), $events[0]->body());
        $this->assertStringContainsString((string) $valueToSend, $events[0]->body());
    }

    /**
     * @test
     */
    public function shouldSendMoneyFromDefaultUserToShopKeeperUser(): void
    {
        $payerId = Id::fromString('payer-id');
        $payeeId = Id::fromString('payee-id');
        $valueToSend = 50;
        $expectedPayerBalance = 50;
        $expectedPayeeBalance = 150;
        $expectedEvents = TransactionCreated::create(
            $payerId->toString(),
            $payeeId->toString(),
            $valueToSend,
            new DateTimeImmutable()
        );

        $repository = new InMemoryUserRepository([
            $this->createDefaultUser($payerId),
            $this->createStorekeeperUser($payeeId),
        ]);
        $eventBus = new MessageBus();
        $eventStore = new InMemoryEventStore(new Serializer([new ObjectNormalizer()], [new JsonEncoder()]));
        $transactionAuthClient = new FakeTransactionAuthClient(true);
        $handler = new SendMoneyHandler($eventBus, $eventStore, $repository, $transactionAuthClient);

        $command = new SendMoney(
            $payerId->toString(),
            $payeeId->toString(),
            $valueToSend
        );

        $handler($command);

        $foundPayer = $repository->findOneByIdOrNull($payerId);
        assert($foundPayer instanceof User);
        $foundPayee = $repository->findOneByIdOrNull($payeeId);
        assert($foundPayee instanceof User);

        $this->assertEquals($expectedPayerBalance, $foundPayer->balance());
        $this->assertEquals($expectedPayeeBalance, $foundPayee->balance());

        $events = $eventStore->allStoredEvents();
        $this->assertCount(1, $events);
        $this->assertEquals(get_class($expectedEvents), $events[0]->typeName());
        $this->assertStringContainsString($foundPayer->id()->toString(), $events[0]->body());
        $this->assertStringContainsString($foundPayee->id()->toString(), $events[0]->body());
        $this->assertStringContainsString((string) $valueToSend, $events[0]->body());
    }

    /**
     * @test
     */
    public function shouldThrowExceptionWhenSendMoneyFromShopKeeperUser(): void
    {
        $this->expectException(CannotSendMoney::class);

        $payerId = Id::fromString('payer-id');
        $payeeId = Id::fromString('payee-id');
        $valueToSend = 50;

        $repository = new InMemoryUserRepository([
            $this->createStorekeeperUser($payerId),
            $this->createDefaultUser($payeeId),
        ]);
        $eventBus = new MessageBus();
        $eventStore = new InMemoryEventStore(new Serializer([new ObjectNormalizer()], [new JsonEncoder()]));
        $transactionAuthClient = new FakeTransactionAuthClient(true);
        $handler = new SendMoneyHandler($eventBus, $eventStore, $repository, $transactionAuthClient);

        $command = new SendMoney(
            $payerId->toString(),
            $payeeId->toString(),
            $valueToSend
        );

        $handler($command);
    }

    /**
     * @test
     */
    public function shouldThrowExceptionWhenPayerUserNotFound(): void
    {
        $this->expectException(UserNotFound::class);
        $this->expectExceptionMessage('User not found with given ID non-existent-payer-user.');

        $payerId = Id::fromString('payer-id');
        $payeeId = Id::fromString('payee-id');
        $valueToSend = 50;

        $repository = new InMemoryUserRepository([
            $this->createStorekeeperUser($payerId),
            $this->createDefaultUser($payeeId),
        ]);
        $eventBus = new MessageBus();
        $eventStore = new InMemoryEventStore(new Serializer([new ObjectNormalizer()], [new JsonEncoder()]));
        $transactionAuthClient = new FakeTransactionAuthClient(true);
        $handler = new SendMoneyHandler($eventBus, $eventStore, $repository, $transactionAuthClient);

        $command = new SendMoney(
            Id::fromString('non-existent-payer-user')->toString(),
            $payeeId->toString(),
            $valueToSend
        );

        $handler($command);
    }

    /**
     * @test
     */
    public function shouldThrowExceptionWhenPayeeUserNotFound(): void
    {
        $this->expectException(UserNotFound::class);
        $this->expectExceptionMessage('User not found with given ID non-existent-payee-user.');

        $payerId = Id::fromString('payer-id');
        $payeeId = Id::fromString('payee-id');
        $valueToSend = 50;

        $repository = new InMemoryUserRepository([
            $this->createDefaultUser($payerId),
            $this->createDefaultUser($payeeId),
        ]);
        $eventBus = new MessageBus();
        $eventStore = new InMemoryEventStore(new Serializer([new ObjectNormalizer()], [new JsonEncoder()]));
        $transactionAuthClient = new FakeTransactionAuthClient(true);
        $handler = new SendMoneyHandler($eventBus, $eventStore, $repository, $transactionAuthClient);

        $command = new SendMoney(
            $payerId->toString(),
            Id::fromString('non-existent-payee-user')->toString(),
            $valueToSend
        );

        $handler($command);
    }

    /**
     * @test
     */
    public function shouldThrowExceptionWhenTransactionIsUnauthorized(): void
    {
        $this->expectException(TransactionUnauthorized::class);
        $this->expectExceptionMessage(
            'Transaction Unauthorized from payerId payer-id to payeeId payee-id with value 50.'
        );

        $payerId = Id::fromString('payer-id');
        $payeeId = Id::fromString('payee-id');
        $valueToSend = 50;

        $repository = new InMemoryUserRepository([
            $this->createDefaultUser($payerId),
            $this->createDefaultUser($payeeId),
        ]);
        $eventBus = new MessageBus();
        $eventStore = new InMemoryEventStore(new Serializer([new ObjectNormalizer()], [new JsonEncoder()]));
        $transactionAuthClient = new FakeTransactionAuthClient(false);
        $handler = new SendMoneyHandler($eventBus, $eventStore, $repository, $transactionAuthClient);

        $command = new SendMoney(
            $payerId->toString(),
            $payeeId->toString(),
            $valueToSend
        );

        $handler($command);
    }

    /**
     * @psalm-param Id<User> $id
     */
    private function createDefaultUser(Id $id): DefaultUser
    {
        return DefaultUser::create(
            $id,
            'Foo Bar',
            '88498957044',
            'foo@bar.com',
            new Wallet(100)
        );
    }

    /**
     * @psalm-param Id<User> $id
     */
    private function createStorekeeperUser(Id $id): StoreKeeperUser
    {
        return StoreKeeperUser::create(
            $id,
            'Foo Bar',
            '88498957044',
            'foo@bar.com',
            new Wallet(100)
        );
    }
}
