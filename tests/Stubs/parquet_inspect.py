#!/usr/bin/env python3
"""Read a Parquet file via pyarrow and emit JSON for independent validation.

Invoked by ParquetCrossValidationTest (PHP) to prove our Parquet output is
readable by an independent reader (Apache Arrow C++), not just by the same
library that produced it.
"""

from __future__ import annotations

import datetime as dt
import json
import sys
from decimal import Decimal

import pyarrow.parquet as pq


def _jsonable(value):
    if value is None:
        return None
    if isinstance(value, (bytes, bytearray)):
        try:
            return value.decode("utf-8")
        except UnicodeDecodeError:
            return value.hex()
    if isinstance(value, Decimal):
        return str(value)
    if isinstance(value, (dt.datetime, dt.date)):
        return value.isoformat()
    if isinstance(value, (int, float, bool, str)):
        return value
    return str(value)


def main(path: str) -> int:
    table = pq.read_table(path)
    schema = [
        {"name": field.name, "type": str(field.type)} for field in table.schema
    ]
    rows = [
        {k: _jsonable(v) for k, v in record.items()}
        for record in table.to_pylist()
    ]
    sys.stdout.write(
        json.dumps({"num_rows": table.num_rows, "schema": schema, "rows": rows})
    )
    return 0


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.stderr.write("usage: parquet_inspect.py <path>\n")
        sys.exit(2)
    sys.exit(main(sys.argv[1]))
