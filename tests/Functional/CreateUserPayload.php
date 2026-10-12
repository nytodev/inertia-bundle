<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateUserPayload
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly string $email = '',
        #[Assert\Positive]
        public readonly int $age = 0,
        #[Assert\Length(min: 5)]
        #[Assert\Regex(pattern: '/^\d+$/')]
        public readonly string $code = '12345',
    ) {
    }
}
