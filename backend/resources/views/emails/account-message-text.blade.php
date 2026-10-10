Business Book
{!! $title !!}

Hello {!! $recipient_name !!},

@foreach ($lines as $line)
{!! $line !!}

@endforeach
@if ($action_url)
{!! $action_label !!}: {!! $action_url !!}
@endif
@if ($unsubscribe_url)
Manage promotional messages: {!! $unsubscribe_url !!}
@endif

Never share passwords or verification codes.

