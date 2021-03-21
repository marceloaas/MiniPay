<?php

declare(strict_types=1);

namespace MiniPay\Core\User\Domain;

use Doctrine\ORM\Mapping as ORM;
use MiniPay\Core\User\Domain\Exception\Insufficientbalance;

/**
 * @ORM\Embeddable()
 */
final class Account
{
    /** @ORM\Column(type="decimal", precision=8, scale=2, nullable=true) */
    private float $amount;

    public function __construct(float $amount)
    {
        $this->amount = $amount;
    }

    public function balance(): float
    {
        return $this->amount;
    }

    public function withdraw(float $amountToWithdraw): void
    {
        if ($amountToWithdraw > $this->amount) {
            throw Insufficientbalance::forWithdraw($this->amount, $amountToWithdraw);
        }

        $this->amount -= $amountToWithdraw;
    }
}
