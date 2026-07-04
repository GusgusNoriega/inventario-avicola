<header class="java-control-header card">
  <div>
    <p class="eyebrow">{{ $eyebrow }}</p>
    <h1>{{ $title }}</h1>
    <p>{{ $description }}</p>
  </div>
  @if (!empty($showMenu))
    <a class="menu-return-btn" href="{{ route('menu') }}">
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M4 6h7v7H4z"></path><path d="M13 6h7v7h-7z"></path>
        <path d="M4 15h7v3H4z"></path><path d="M13 15h7v3h-7z"></path>
      </svg>
      <span>Menú</span>
    </a>
  @else
    <a class="menu-return-btn java-back-btn" href="{{ route('control-javas') }}">
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M19 12H5M11 6l-6 6 6 6"></path>
      </svg>
      <span>Control de javas</span>
    </a>
  @endif
</header>
