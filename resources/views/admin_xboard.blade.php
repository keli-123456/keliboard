<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  @php
    $adminAssetBase = public_path('assets/admin-xboard/assets');
    $adminIndexJsVersion = is_file($adminAssetBase . '/index.js') ? filemtime($adminAssetBase . '/index.js') : time();
    $adminIndexCssVersion = is_file($adminAssetBase . '/index.css') ? filemtime($adminAssetBase . '/index.css') : time();
    $baseUrl = rtrim(url('/'), '/');
    $adminPath = trim((string) $secure_path, '/');
  @endphp
  <script>
    window.settings = {
      base_url: "{{ $baseUrl }}",
      title: "{{ $title }}",
      version: "{{ $version }}",
      logo: "{{ $logo }}",
      secure_path: "{{ $secure_path }}",
      admin_path: "{{ $adminPath }}",
      admin_base_path: "/{{ $adminPath }}",
      xadmin_base_path: "/{{ $adminPath }}/xadmin",
      staff_base_path: "/{{ $adminPath }}/staff",
      api_v1_base: "{{ $baseUrl }}/api/v1",
      api_v2_base: "{{ $baseUrl }}/api/v2",
    };
  </script>
  <script type="module" crossorigin src="/assets/admin-xboard/assets/index.js?v={{ $adminIndexJsVersion }}"></script>
  <link rel="stylesheet" crossorigin href="/assets/admin-xboard/assets/index.css?v={{ $adminIndexCssVersion }}" />
</head>

<body>
  <div id="root"></div>
</body>

</html>
