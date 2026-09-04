<?php
declare(strict_types=1);

use DeployEcommerce\Inbox\Ui\Component\Listing\Column\MessageActions;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;

/**
 * The actions column is coupled to how Magento's actions column invokes a callback, and
 * that coupling is easy to get wrong silently.
 *
 * Magento builds the call as `_.compact([cb.target, cb.params])` and applies it, so the
 * configured params array arrives at the component method as a SINGLE argument: the
 * method receives [42], not 42. Passing that straight into a request produced `id[]=42`,
 * which PHP cast to 1, so the modal loaded a message that did not exist and reported only
 * that it could not be loaded. The JS normalises the array; these tests pin the shape it
 * depends on.
 */
function messageActions(): MessageActions
{
    $processor = test()->createMock(Processor::class);
    $context = test()->createMock(ContextInterface::class);
    $context->method('getProcessor')->willReturn($processor);

    return new MessageActions(
        $context,
        test()->createMock(UiComponentFactory::class),
        [],
        ['name' => 'actions']
    );
}

function sourceWithOneRow(int $id = 42): array
{
    return ['data' => ['items' => [['message_id' => $id, 'title' => 'A message']]]];
}

it('adds a view action for each row', function () {
    $result = messageActions()->prepareDataSource(sourceWithOneRow());

    expect($result['data']['items'][0]['actions'])->toHaveKey('view');
});

it('targets the modal component that actually exists in the rendered tree', function () {
    $action = messageActions()->prepareDataSource(sourceWithOneRow())['data']['items'][0]['actions']['view'];

    // Magento nests listing children under <namespace>.<namespace>, so the modal really
    // does sit at this doubled path. Getting it wrong fails silently at runtime.
    expect($action['callback'][0]['provider'])->toBe(
        'deployecommerce_inbox_message_listing.deployecommerce_inbox_message_listing'
        . '.message_modal.message_view'
    )->and($action['callback'][0]['target'])->toBe('openMessage');
});

it('passes the message id as params, which reaches the component as an array', function () {
    $action = messageActions()->prepareDataSource(sourceWithOneRow(42))['data']['items'][0]['actions']['view'];

    expect($action['callback'][0]['params'])->toBe([42]);
});

it('omits href so the browser does not navigate before the callback runs', function () {
    $action = messageActions()->prepareDataSource(sourceWithOneRow())['data']['items'][0]['actions']['view'];

    expect($action)->not->toHaveKey('href');
});

it('skips rows with no id rather than emitting a broken action', function () {
    $source = ['data' => ['items' => [['title' => 'orphan']]]];

    expect(messageActions()->prepareDataSource($source)['data']['items'][0])->not->toHaveKey('actions');
});

it('leaves a data source with no items untouched', function () {
    expect(messageActions()->prepareDataSource(['data' => []]))->toBe(['data' => []]);
});
