
@section('content')

@if($errors->any())
<strong>{{$errors->first()}}</strong>
@endif

<a href="{{ route('mpesaregisterurl') }}" class="button btn "> Register C2B URL </a>
@stop
