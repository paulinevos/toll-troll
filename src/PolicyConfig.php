<?php

namespace Vos\TollTroll;

use Attribute;
use Illuminate\Support\Facades\Validator;
use ReflectionClass;
use Vos\TollTroll\Contracts\Policy as PolicyContract;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class PolicyConfig
{
    /** @param list<array{provider: ?string, model: ?string}>|null $classifiers Null inherits; a list replaces all inherited classifiers. */
    public function __construct(public ?array $classifiers = null, public ?int $timeout = null) {}

    /** @return array{classifiers: list<array{provider: ?string, model: ?string}>, timeout: int} */
    public static function resolve(PolicyContract $policy, ?self $evaluation = null, ?self $override = null, ?array $defaults = null): array
    {
        $defaults ??= config('toll-troll');
        $settings = ['classifiers' => $defaults['classifiers'] ?? null, 'timeout' => $defaults['timeout'] ?? null];
        $attributes = (new ReflectionClass($policy))->getAttributes(self::class);
        foreach ([$attributes === [] ? null : $attributes[0]->newInstance(), $evaluation, $override] as $layer) {
            if ($layer?->classifiers !== null) {
                $settings['classifiers'] = $layer->classifiers;
            }
            if ($layer?->timeout !== null) {
                $settings['timeout'] = $layer->timeout;
            }
        }

        $settings = Validator::make($settings, [
            'timeout' => ['required', 'integer', 'min:1'],
            'classifiers' => ['required', 'array', 'list', 'min:1'],
            'classifiers.*' => ['required', 'array:provider,model'],
            'classifiers.*.provider' => ['present', 'nullable', 'string'],
            'classifiers.*.model' => ['present', 'nullable', 'string'],
        ])->validate();
        $settings['timeout'] = (int) $settings['timeout'];

        return $settings;
    }
}
