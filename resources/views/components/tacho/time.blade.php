@props(['value', 'tz', 'format' => 'D d M H:i'])
@use('App\Tachograph\Reporting\Format')
<time datetime="{{ $value }}" title="{{ Format::utc($value) }}" {{ $attributes->class('whitespace-nowrap') }}>{{ Format::local($value, $tz, $format) }}</time>
