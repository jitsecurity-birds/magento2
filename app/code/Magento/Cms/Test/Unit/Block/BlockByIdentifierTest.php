<?php

/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Cms\Test\Unit\Block;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\GetBlockByIdentifierInterface;
use Magento\Cms\Block\BlockByIdentifier;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filter\Template;
use Magento\Framework\View\Element\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BlockByIdentifierTest extends TestCase
{
    private const STUB_MODULE_OUTPUT_DISABLED = false;
    private const STUB_EXISTING_IDENTIFIER = 'existingOne';
    private const STUB_UNAVAILABLE_IDENTIFIER = 'notExists';
    private const STUB_DEFAULT_STORE = 1;
    private const STUB_CMS_BLOCK_ID = 1;
    private const STUB_CONTENT = 'Content';

    private const ASSERT_EMPTY_BLOCK_HTML = '';
    private const ASSERT_CONTENT_HTML = self::STUB_CONTENT;
    private const ASSERT_NO_CACHE_IDENTITIES = [];
    private const ASSERT_UNAVAILABLE_IDENTIFIER_BASED_IDENTITIES = [
        BlockByIdentifier::CACHE_KEY_PREFIX . '_' . self::STUB_UNAVAILABLE_IDENTIFIER,
        BlockByIdentifier::CACHE_KEY_PREFIX . '_' . self::STUB_UNAVAILABLE_IDENTIFIER . '_' . self::STUB_DEFAULT_STORE
    ];

    /** @var MockObject|GetBlockByIdentifierInterface */
    private $getBlockByIdentifierMock;

    /** @var MockObject|StoreManagerInterface */
    private $storeManagerMock;

    /** @var MockObject|FilterProvider */
    private $filterProviderMock;

    /** @var MockObject|StoreInterface */
    private $storeMock;

    protected function setUp()
    {
        $this->storeMock = $this->createMock(StoreInterface::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->storeManagerMock->method('getStore')->willReturn($this->storeMock);

        $this->getBlockByIdentifierMock = $this->createMock(GetBlockByIdentifierInterface::class);

        $this->filterProviderMock = $this->createMock(FilterProvider::class);
        $this->filterProviderMock->method('getBlockFilter')->willReturn($this->getPassthroughFilterMock());
    }

    public function testBlockReturnsEmptyStringWhenNoIdentifierProvided(): void
    {
        // Given
        $missingIdentifierBlock = $this->getTestedBlockUsingIdentifier(null);
        $this->storeMock->method('getId')->willReturn(self::STUB_DEFAULT_STORE);

        // Expect
        $this->assertSame(self::ASSERT_EMPTY_BLOCK_HTML, $missingIdentifierBlock->toHtml());
        $this->assertSame(self::ASSERT_NO_CACHE_IDENTITIES, $missingIdentifierBlock->getIdentities());
    }

    public function testBlockReturnsEmptyStringWhenIdentifierProvidedNotFound(): void
    {
        // Given
        $this->getBlockByIdentifierMock->method('execute')->willThrowException(
            new NoSuchEntityException(__('NoSuchEntityException'))
        );
        $missingIdentifierBlock = $this->getTestedBlockUsingIdentifier(self::STUB_UNAVAILABLE_IDENTIFIER);
        $this->storeMock->method('getId')->willReturn(self::STUB_DEFAULT_STORE);

        // Expect
        $this->assertSame(self::ASSERT_EMPTY_BLOCK_HTML, $missingIdentifierBlock->toHtml());
        $this->assertSame(
            self::ASSERT_UNAVAILABLE_IDENTIFIER_BASED_IDENTITIES,
            $missingIdentifierBlock->getIdentities()
        );
    }

    public function testBlockReturnsCmsContentsWhenIdentifierFound(): void
    {
        // Given
        $cmsBlockMock = $this->getCmsBlockMock(
            self::STUB_CMS_BLOCK_ID,
            self::STUB_EXISTING_IDENTIFIER,
            self::STUB_CONTENT
        );
        $this->storeMock->method('getId')->willReturn(self::STUB_DEFAULT_STORE);
        $this->getBlockByIdentifierMock->method('execute')
            ->with(self::STUB_EXISTING_IDENTIFIER, self::STUB_DEFAULT_STORE)
            ->willReturn($cmsBlockMock);
        $block = $this->getTestedBlockUsingIdentifier(self::STUB_EXISTING_IDENTIFIER);

        // Expect
        $this->assertSame(self::ASSERT_CONTENT_HTML, $block->toHtml());
    }

    public function testBlockCacheIdentitiesContainExplicitScopeInformation(): void
    {
        // Given
        $cmsBlockMock = $this->getCmsBlockMock(
            self::STUB_CMS_BLOCK_ID,
            self::STUB_EXISTING_IDENTIFIER,
            self::STUB_CONTENT
        );
        $this->storeMock->method('getId')->willReturn(self::STUB_DEFAULT_STORE);
        $this->getBlockByIdentifierMock->method('execute')
            ->with(self::STUB_EXISTING_IDENTIFIER, self::STUB_DEFAULT_STORE)
            ->willReturn($cmsBlockMock);
        $block = $this->getTestedBlockUsingIdentifier(self::STUB_EXISTING_IDENTIFIER);

        // When
        $identities = $block->getIdentities();

        // Then
        $this->assertContains($this->getCacheKeyStubById(self::STUB_CMS_BLOCK_ID), $identities);
        $this->assertContains(
            $this->getCacheKeyStubByIdentifier(self::STUB_EXISTING_IDENTIFIER, self::STUB_DEFAULT_STORE),
            $identities
        );
    }

    /**
     * Initializes the tested block with injecting the references required by parent classes.
     *
     * @param string|null $identifier
     * @return BlockByIdentifier
     */
    private function getTestedBlockUsingIdentifier(?string $identifier): BlockByIdentifier
    {
        $eventManagerMock = $this->createMock(ManagerInterface::class);
        $scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $scopeConfigMock->method('getValue')->willReturn(self::STUB_MODULE_OUTPUT_DISABLED);

        $contextMock = $this->createMock(Context::class);
        $contextMock->method('getEventManager')->willReturn($eventManagerMock);
        $contextMock->method('getScopeConfig')->willReturn($scopeConfigMock);

        return new BlockByIdentifier(
            $this->getBlockByIdentifierMock,
            $this->storeManagerMock,
            $this->filterProviderMock,
            $contextMock,
            ['identifier' => $identifier]
        );
    }

    /**
     * Mocks the CMS Block object for further play
     *
     * @param int $entityId
     * @param string $identifier
     * @param string $content
     * @return MockObject|BlockInterface
     */
    private function getCmsBlockMock(int $entityId, string $identifier, string $content): BlockInterface
    {
        $cmsBlock = $this->createMock(BlockInterface::class);

        $cmsBlock->method('getId')->willReturn($entityId);
        $cmsBlock->method('getIdentifier')->willReturn($identifier);
        $cmsBlock->method('getContent')->willReturn($content);

        return $cmsBlock;
    }

    /**
     * Creates mock of the Filter that actually is doing nothing
     *
     * @return MockObject|Template
     */
    private function getPassthroughFilterMock(): Template
    {
        $filterMock = $this->getMockBuilder(Template::class)
            ->disableOriginalConstructor()
            ->setMethods(['setStoreId', 'filter'])
            ->getMock();
        $filterMock->method('setStoreId')->willReturnSelf();
        $filterMock->method('filter')->willReturnArgument(0);

        return $filterMock;
    }

    private function getCacheKeyStubByIdentifier(string $identifier, int $storeId = self::STUB_DEFAULT_STORE): string
    {
        return BlockByIdentifier::CACHE_KEY_PREFIX . '_' . $identifier . '_' . $storeId;
    }

    private function getCacheKeyStubById(int $cmsBlockId): string
    {
        return BlockByIdentifier::CACHE_KEY_PREFIX . '_' . $cmsBlockId;
    }
}
