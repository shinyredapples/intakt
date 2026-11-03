Repository: symfony/symfony. Template: Bug Report (`1_Bug_report.yaml`). Not posted: the owner approves this text first. The fix looks small (see Possible Solution).

Title: [Config] A NaN value skips the type check and the `validate()` closures of its node

### Symfony version(s) affected

6.4.49, 7.4.22, 8.1.5 (also 8.1.x-dev 4a0e7dd1645b and 8.2.x-dev 0d10198cc52a)

### Description

`BaseNode::merge()`, `normalize()` and `finalize()` detect a placeholder with `$value !== $placeholders = self::resolvePlaceholderValue($value)`. For `NAN` the method returns the value unchanged, but `NAN !== NAN` is true, so the node takes the placeholder branch: it iterates over a float (`foreach() argument must be of type array|object, float given`) and returns the value without its type check and without running its `validate()` closures. A YAML `.NaN` reaches a `floatNode()` this way, and nothing reports it.

`min()` and `max()` would not refuse it after a fix either, since `NAN < min` and `NAN > max` are both false; a `validate()` closure that tests `is_nan()` is what catches it, and today it never runs. The parent array nodes are not affected: `resolvePlaceholderValue()` returns the same array, which PHP treats as identical to itself even when it holds a NaN. With or without sibling keys, the NaN passes silently.

### How to reproduce

```php
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class Ceiling implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('root');
        $tree->getRootNode()->children()->floatNode('ceiling')->min(1)->max(100)
            ->validate()->always(static fn (): never => throw new InvalidConfigurationException('validation closure ran'));

        return $tree;
    }
}

var_dump((new Processor())->processConfiguration(new Ceiling(), [['ceiling' => NAN]]));
```

Actual: two `foreach()` warnings from `BaseNode.php` (`normalize()`, then `finalize()`), then `array(1) { ["ceiling"]=> float(NAN) }`.

Expected: `InvalidConfigurationException: validation closure ran`, the same as for any other float.

### Possible Solution

`resolvePlaceholderValue()` only resolves strings, so the four comparisons in `BaseNode` (two in `merge()`, one each in `normalize()` and `finalize()`) can be guarded with `\is_string($value)`, for example `if (\is_string($value) && $value !== $placeholders = self::resolvePlaceholderValue($value))`.

### Additional Context

_No response_
