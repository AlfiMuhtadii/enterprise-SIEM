FROM python:3.12-slim
WORKDIR /app
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY main.py ./
COPY xdr_event_contracts.py ./
EXPOSE 8096
CMD ["python", "-m", "uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8096"]
