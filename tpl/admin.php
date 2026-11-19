<?php declare(strict_types=1);
use PrivateBin\I18n;

$AUTHENTICATED = $AUTHENTICATED ?? false;
$CSRF = $CSRF ?? '';
$NAME = $NAME ?? 'PrivateBin';
$VERSION = $VERSION ?? '';
$I18N = $I18N ?? '{}';
?><!DOCTYPE html>
<html lang="<?php echo I18n::getLanguage(); ?>"<?php echo I18n::isRtl() ? ' dir="rtl"' : ''; ?>>
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex, nofollow" />
		<title><?php echo I18n::_($NAME); ?> &ndash; <?php echo I18n::_('Administration'); ?></title>
		<link type="text/css" rel="stylesheet" href="css/bootstrap5/bootstrap<?php echo I18n::isRtl() ? '.rtl' : ''; ?>-5.3.8.css" />
		<link type="text/css" rel="stylesheet" href="css/admin.css?<?php echo rawurlencode($VERSION); ?>" />
		<?php
		if (isset($this) && $this instanceof \PrivateBin\View) {
			$this->_scriptTag('js/admin.js', 'defer');
		} else {
			// the template is also included by tests, without a view instance
			echo '<script defer type="text/javascript" src="js/admin.js"></script>', PHP_EOL;
		}
		?>
		<link rel="icon" type="image/png" href="img/favicon-32x32.png" sizes="32x32" />
	</head>
	<body data-csrf="<?php echo I18n::encode($CSRF); ?>" data-i18n="<?php echo I18n::encode($I18N); ?>"<?php echo $AUTHENTICATED ? ' data-authenticated="1"' : ''; ?>>
		<header class="admin-header">
			<div class="container-fluid d-flex align-items-center gap-3">
				<a href="./" class="admin-brand"><?php echo I18n::encode($NAME); ?></a>
				<span class="badge text-bg-secondary"><?php echo I18n::_('Administration'); ?></span>
				<a href="./" class="ms-auto link-light"><?php echo I18n::_('Back to the paste bin'); ?></a>
			</div>
		</header>
		<main class="container-fluid py-4">
<?php if (!$AUTHENTICATED) : ?>
			<div class="row justify-content-center">
				<div class="col-12 col-sm-8 col-md-6 col-lg-4">
					<div class="card shadow-sm">
						<div class="card-body">
							<h1 class="h4 card-title mb-3"><?php echo I18n::_('Administration'); ?></h1>
							<form id="loginform" novalidate>
								<label for="token" class="form-label"><?php echo I18n::_('Administration token'); ?></label>
								<input type="password" id="token" name="token" class="form-control" autocomplete="current-password"
									minlength="15" maxlength="15" pattern="[A-Za-z0-9]{15}" required autofocus />
								<div class="form-text"><?php echo I18n::_('15 alphanumeric characters.'); ?></div>
								<div id="loginerror" class="alert alert-danger mt-3 d-none" role="alert"></div>
								<button type="submit" class="btn btn-primary w-100 mt-3"><?php echo I18n::_('Sign in'); ?></button>
							</form>
						</div>
					</div>
				</div>
			</div>
<?php else : ?>
			<div id="adminerror" class="alert alert-danger d-none" role="alert"></div>
			<div id="adminstatus" class="alert alert-success d-none" role="alert"></div>
			<div class="card mb-3">
				<div class="card-body">
					<form id="filterform" class="row g-2 align-items-end">
						<div class="col-12 col-md-3">
							<label for="q" class="form-label"><?php echo I18n::_('Search'); ?></label>
							<input type="search" id="q" name="q" class="form-control" placeholder="<?php echo I18n::encode(I18n::_('Name, ID or tag')); ?>" />
						</div>
						<div class="col-6 col-md-2">
							<label for="tag" class="form-label"><?php echo I18n::_('Tag'); ?></label>
							<select id="tag" name="tag" class="form-select">
								<option value=""><?php echo I18n::_('All'); ?></option>
							</select>
						</div>
						<div class="col-6 col-md-2">
							<label for="from" class="form-label"><?php echo I18n::_('Created from'); ?></label>
							<input type="date" id="from" name="from" class="form-control" />
						</div>
						<div class="col-6 col-md-2">
							<label for="to" class="form-label"><?php echo I18n::_('Created to'); ?></label>
							<input type="date" id="to" name="to" class="form-control" />
						</div>
						<div class="col-6 col-md-3">
							<label for="status" class="form-label"><?php echo I18n::_('Status'); ?></label>
							<select id="status" name="status" class="form-select">
								<option value="all"><?php echo I18n::_('All'); ?></option>
								<option value="active"><?php echo I18n::_('Active'); ?></option>
								<option value="expired"><?php echo I18n::_('Expired'); ?></option>
							</select>
						</div>
						<div class="col-12 d-flex flex-wrap gap-2 align-items-center">
							<button type="submit" class="btn btn-primary"><?php echo I18n::_('Apply filters'); ?></button>
							<button type="reset" id="resetfilters" class="btn btn-secondary"><?php echo I18n::_('Reset'); ?></button>
							<label for="sort" class="form-label ms-md-auto mb-0"><?php echo I18n::_('Order'); ?></label>
							<select id="sort" name="sort" class="form-select w-auto">
								<option value="created_desc"><?php echo I18n::_('Newest first'); ?></option>
								<option value="created_asc"><?php echo I18n::_('Oldest first'); ?></option>
							</select>
							<label for="perpage" class="form-label mb-0"><?php echo I18n::_('Per page'); ?></label>
							<select id="perpage" name="perpage" class="form-select w-auto">
								<option value="25">25</option>
								<option value="50" selected>50</option>
								<option value="100">100</option>
							</select>
						</div>
					</form>
					<p class="form-text mt-3 mb-0"><?php echo I18n::_('Documents are end-to-end encrypted: only their meta data can be searched, sorted and managed here. The text is not accessible to the server.'); ?></p>
				</div>
			</div>
			<div class="table-responsive">
				<table id="pastetable" class="table table-striped align-middle">
					<thead>
						<tr>
							<th scope="col"><?php echo I18n::_('Name / ID'); ?></th>
							<th scope="col"><?php echo I18n::_('Tag'); ?></th>
							<th scope="col"><?php echo I18n::_('Created'); ?></th>
							<th scope="col"><?php echo I18n::_('Expires'); ?></th>
							<th scope="col" class="text-end"><?php echo I18n::_('Size'); ?></th>
							<th scope="col" class="text-end"><?php echo I18n::_('Actions'); ?></th>
						</tr>
					</thead>
					<tbody id="pastes"></tbody>
				</table>
			</div>
			<div class="d-flex flex-wrap gap-2 align-items-center">
				<button type="button" id="prevpage" class="btn btn-secondary btn-sm" disabled><?php echo I18n::_('Previous'); ?></button>
				<button type="button" id="nextpage" class="btn btn-secondary btn-sm" disabled><?php echo I18n::_('Next'); ?></button>
				<span id="paginationinfo" class="text-body-secondary small"></span>
				<button type="button" id="logout" class="btn btn-outline-danger btn-sm ms-auto"><?php echo I18n::_('Sign out'); ?></button>
			</div>
<?php endif; ?>
		</main>
	</body>
</html>
