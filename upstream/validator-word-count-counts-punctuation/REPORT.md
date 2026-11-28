# [Validator] `WordCount` counts punctuation marks as words

Draft, not filed. Versions: `symfony/validator` v8.2.0-BETA2, PHP 8.5.12, ICU 76.1.

## What happens

`WordCountValidator` splits the value with `IntlBreakIterator::createWordInstance()` and counts every part that is not blank after `trim()`. ICU's word iterator also returns each punctuation mark as a part of its own, so a mark counts as a word: `. . .` passes `new WordCount(min: 3)`, and `tidy-up` counts as three words (`tidy`, `-`, `up`).

`reproduce.php` validates three values made of punctuation alone against `new WordCount(min: 3)` and prints:

```
". . ." => accepted as 3 words
"- - -" => accepted as 3 words
"! ? !" => accepted as 3 words
punctuation marks count as words
```

## Expected

Each value holds no word, so `min: 3` refuses it. ICU tells words from other parts through `IntlBreakIterator::getRuleStatus()`: a part whose status is below `IntlBreakIterator::WORD_NONE_LIMIT` is no word.

## Effect in Intakt

A finding's `title` in `src/Core/Review/Contract/Finding.php` must have 3 to 6 words. With `#[WordCount(min: 3, max: 6)]` a reviewer could pass `. . .` as a title, and a valid six-word title such as `The tidy-up step breaks cart discounts` was refused as too long, which makes the whole verdict unreadable. So the title does not use `WordCount`: a `#[Callback]` (`Finding::refuseOutsideThreeToSixWords()`) counts the parts between white space that hold a letter or digit and refuses fewer than three or more than six of them (`VerdictParserTest`).

## Upstream status

- **Pinned versions:** still fails (`php bin/console intakt:upstream:run`: `still fails  validator-word-count-counts-punctuation`).
- **Development branch:** `symfony/symfony` branch `8.1` at 46cb370c (2026-11-19) counts the same way: it keeps every part that is not blank after `trim()`.
- **Pull requests and issues:** not searched from this build's sandbox; the duplicate check is the owner's before anything is filed.
- **Status:** `draft`, waiting for the owner's duplicate check.
