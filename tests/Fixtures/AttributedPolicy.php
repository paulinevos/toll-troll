<?php

namespace Vos\TollTroll\Tests\Fixtures;

use Vos\TollTroll\PolicyConfig;

#[PolicyConfig(classifiers: [['provider' => 'openai', 'model' => 'attribute-a'], ['provider' => 'openai', 'model' => 'attribute-b']], timeout: 17)]
class AttributedPolicy extends DomainPolicy {}
