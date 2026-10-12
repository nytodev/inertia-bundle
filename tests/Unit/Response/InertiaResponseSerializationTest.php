<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Unit\Response;

use Nytodev\InertiaBundle\Response\InertiaResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Twig\Environment;

/**
 * Tests optional Symfony Normalizer support in InertiaResponse::build().
 *
 * Normalization is opt-in: it only runs when a non-null $serializationContext
 * is passed to build(). The normalizer operates on the full page object AFTER
 * all prop types are resolved. When the normalizer service is unavailable and a
 * context is passed, a LogicException is thrown.
 */
final class InertiaResponseSerializationTest extends TestCase
{
    /** @var Environment&MockObject */
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = $this->createMock(Environment::class);
        $this->twig->method('render')->willReturn('<html></html>');
    }

    public function testBuildWithNullContextAndNoSerializerPropsPassThroughUnchanged(): void
    {
        $response = new InertiaResponse($this->twig, 'base.html.twig');

        $request = Request::create('/test');
        $request->headers->set('X-Inertia', 'true');

        $result = $response->build(
            component: 'Users/Index',
            props: ['name' => 'Alice'],
            url: '/test',
            version: null,
            request: $request,
            serializationContext: null,
        );

        self::assertSame(200, $result->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $result->getContent(), true);
        self::assertSame('Alice', $data['props']['name']);
    }

    public function testBuildWithContextButNoSerializerThrowsLogicException(): void
    {
        $response = new InertiaResponse($this->twig, 'base.html.twig');
        $request = Request::create('/test');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/normalizer/i');

        $response->build(
            component: 'Users/Index',
            props: ['name' => 'Alice'],
            url: '/test',
            version: null,
            request: $request,
            serializationContext: ['groups' => ['api']],
        );
    }

    public function testBuildWithSerializerAndContextCallsNormalizeOnFullPageObject(): void
    {
        /** @var NormalizerInterface&MockObject $normalizer */
        $normalizer = $this->createMock(NormalizerInterface::class);

        $normalizer
            ->expects(self::once())
            ->method('normalize')
            ->with(
                self::callback(static function (mixed $page): bool {
                    // Full page object is passed — must have component, props, url keys
                    return \is_array($page)
                        && isset($page['component'], $page['props'], $page['url'])
                        && 'Users/Index' === $page['component'];
                }),
                'json',
                self::callback(static fn (array $ctx): bool => isset($ctx['groups']) && ['api'] === $ctx['groups']),
            )
            ->willReturn(['component' => 'Users/Index', 'props' => ['name' => 'Alice', 'errors' => []], 'url' => '/test', 'version' => null]);

        $response = new InertiaResponse($this->twig, 'base.html.twig', $normalizer);
        $request = Request::create('/test');

        $result = $response->build(
            component: 'Users/Index',
            props: ['name' => 'Alice'],
            url: '/test',
            version: null,
            request: $request,
            serializationContext: ['groups' => ['api']],
        );

        self::assertSame(200, $result->getStatusCode());
    }

    public function testBuildWithSerializerAndContextNormalizedValuesAppearInJsonResponse(): void
    {
        /** @var NormalizerInterface&MockObject $normalizer */
        $normalizer = $this->createMock(NormalizerInterface::class);

        $normalizer
            ->method('normalize')
            ->willReturn(['component' => 'Users/Index', 'props' => ['name' => 'Alice normalized', 'errors' => []], 'url' => '/test', 'version' => null]);

        $response = new InertiaResponse($this->twig, 'base.html.twig', $normalizer);

        $request = Request::create('/test');
        $request->headers->set('X-Inertia', 'true');

        $result = $response->build(
            component: 'Users/Index',
            props: ['name' => 'Alice'],
            url: '/test',
            version: null,
            request: $request,
            serializationContext: ['groups' => ['api']],
        );

        self::assertSame(200, $result->getStatusCode());
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $result->getContent(), true);
        self::assertSame('Alice normalized', $data['props']['name']);
    }

    public function testBuildWithSerializerButNullContextSerializerNotCalled(): void
    {
        /** @var NormalizerInterface&MockObject $normalizer */
        $normalizer = $this->createMock(NormalizerInterface::class);

        $normalizer
            ->expects(self::never())
            ->method('normalize');

        $response = new InertiaResponse($this->twig, 'base.html.twig', $normalizer);
        $request = Request::create('/test');

        $result = $response->build(
            component: 'Users/Index',
            props: ['name' => 'Alice'],
            url: '/test',
            version: null,
            request: $request,
            serializationContext: null,
        );

        self::assertSame(200, $result->getStatusCode());
    }

    public function testNormalizeDefaultContextMergedWithUserContext(): void
    {
        /** @var NormalizerInterface&MockObject $normalizer */
        $normalizer = $this->createMock(NormalizerInterface::class);

        $normalizer
            ->expects(self::once())
            ->method('normalize')
            ->with(
                self::anything(),
                'json',
                self::callback(static function (array $ctx): bool {
                    // Defaults must be present
                    return isset($ctx['circular_reference_handler'])
                        && isset($ctx['preserve_empty_objects'])
                        && isset($ctx['enable_max_depth'])
                        // json_encode_options must NOT be present (no-op in normalize path)
                        && !isset($ctx['json_encode_options'])
                        // User context is merged on top
                        && isset($ctx['groups']) && ['api'] === $ctx['groups'];
                }),
            )
            ->willReturn(['component' => 'Users/Index', 'props' => ['errors' => []], 'url' => '/test', 'version' => null]);

        $response = new InertiaResponse($this->twig, 'base.html.twig', $normalizer);
        $request = Request::create('/test');

        $response->build(
            component: 'Users/Index',
            props: [],
            url: '/test',
            version: null,
            request: $request,
            serializationContext: ['groups' => ['api']],
        );
    }

    public function testEmptyErrorsWithRealSerializerStillSerializedAsEmptyJsonObject(): void
    {
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
        $response = new InertiaResponse($this->twig, 'base.html.twig', $serializer);
        $request = Request::create('/test');
        $request->headers->set('X-Inertia', 'true');

        $result = $response->build('Users/Index', [], '/test', null, $request, serializationContext: []);

        self::assertStringContainsString('"errors":{}', (string) $result->getContent());
    }
}
