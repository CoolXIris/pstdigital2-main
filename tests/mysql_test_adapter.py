"""SQLite-backed test adapter for the MySQL storage contract."""

import sqlite3


class MySQLStorageTestConnection:
    def __init__(self, database_path: str) -> None:
        self._connection = sqlite3.connect(database_path)
        self._connection.row_factory = sqlite3.Row
        self._connection.executescript(
            """
            CREATE TABLE IF NOT EXISTS data_tabel (
                id_tabel TEXT, jenis_tabel TEXT, tahun TEXT, judul TEXT,
                link_json TEXT, isi_json TEXT, diambil_pada REAL,
                PRIMARY KEY (id_tabel, tahun)
            );
            CREATE TABLE IF NOT EXISTS tabel_meta (
                id_tabel TEXT PRIMARY KEY, judul TEXT, satuan TEXT, subjek TEXT,
                label_wilayah TEXT, catatan TEXT, last_update_bps TEXT, diambil_pada REAL
            );
            CREATE TABLE IF NOT EXISTS nilai_data (
                id_tabel TEXT, wilayah_id TEXT, wilayah TEXT, turvar_id TEXT,
                turvar TEXT, tahun TEXT, turtahun TEXT, nilai REAL,
                PRIMARY KEY (id_tabel, wilayah_id, turvar_id, tahun, turtahun)
            );
            CREATE INDEX IF NOT EXISTS idx_nilai_wil ON nilai_data(id_tabel, wilayah);
            CREATE TABLE IF NOT EXISTS editorial_cache (
                cache_key TEXT PRIMARY KEY, isi_json TEXT, diambil_pada REAL
            );
            """
        )

    @staticmethod
    def _sqlite_query(query: str) -> str:
        if "ON DUPLICATE KEY UPDATE" in query:
            query = query.split("ON DUPLICATE KEY UPDATE", 1)[0]
            query = query.replace("INSERT INTO", "INSERT OR REPLACE INTO", 1)
        return query.replace("AS UNSIGNED", "AS INTEGER")

    def execute(self, query: str, parameters: tuple = ()):
        return self._connection.execute(self._sqlite_query(query), parameters)

    def executemany(self, query: str, parameters: list):
        return self._connection.executemany(self._sqlite_query(query), parameters)

    def commit(self) -> None:
        self._connection.commit()

    def close(self) -> None:
        self._connection.close()
