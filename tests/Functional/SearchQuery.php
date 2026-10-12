<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Functional;

use Symfony\Component\Validator\Constraints as Assert;

final class SearchQuery
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3)]
        public readonly string $term = '',
    ) {
    }
}
