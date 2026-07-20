CREATE TABLE sys_file_reference (
	tx_mindfula11y_decorative smallint(5) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE tt_content (
	# Explicit definitions: the heading-type selects carry an itemsProcFunc, so
	# schema auto-generation falls back to a nullable TEXT column for these
	# short values. Deliberately nullable: a NOT NULL conversion would fail the
	# ALTER on strict-mode MySQL/MariaDB wherever existing rows hold NULL.
	tx_mindfula11y_headingtype varchar(10) DEFAULT NULL,
	tx_mindfula11y_childheadingtype varchar(10) DEFAULT NULL
);
