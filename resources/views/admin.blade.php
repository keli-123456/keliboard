<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  @php
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
      api_v2_base: "{{ $baseUrl }}/api/v2/{{ $adminPath }}",
    };
  </script>
  <script type="module" crossorigin src="/assets/admin/assets/index.js"></script>
  <link rel="stylesheet" crossorigin href="/assets/admin/assets/index.css" />
  <link rel="stylesheet" crossorigin href="/assets/admin/assets/vendor.css">
  <script src="/assets/admin/locales/en-US.js"></script>
  <script src="/assets/admin/locales/zh-CN.js"></script>
  <script src="/assets/admin/locales/ko-KR.js"></script>
</head>

<body>
  <div id="root"></div>
</body>

</html>
