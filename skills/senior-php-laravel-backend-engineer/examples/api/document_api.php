<?php
declare(strict_types=1);
// Standalone contract/authorization fixture. Real Laravel binding/policies are tested in the host project.
final class InputError extends RuntimeException {}
final class AccessError extends RuntimeException {}

function validatedCreate(array $payload): array {
    // Explicit allowlist: unknown fields are NOT persisted.
    $title = $payload['title'] ?? null;
    if (!is_string($title) || trim($title)==='' || mbLength($title)>180) {
        throw new InputError('title must be 1-180 chars');
    }
    return ['title'=>trim($title)];
}
function mbLength(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
function createDocument(array $principal, array $payload): array {
    if (!in_array('documents:create', $principal['permissions'], true)) throw new AccessError('create denied');
    return ['scope'=>$principal['scope']] + validatedCreate($payload);
}
function mayWrite(array $principal, array $document): bool {
    return in_array('documents:update', $principal['permissions'], true)
        && $principal['scope'] === $document['scope'];
}
function updateDocument(array $principal, array $document, array $payload): array {
    if (!mayWrite($principal, $document)) throw new AccessError('denied');
    return array_replace($document, validatedCreate($payload));
}
function verify(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function throws(string $class, callable $fn): void {
    try { $fn(); } catch (Throwable $e) {verify($e instanceof $class, 'wrong error '.get_class($e)); return;}
    throw new RuntimeException('expected exception '.$class);
}
$one=['scope'=>'one','permissions'=>['documents:create','documents:update']];
$two=['scope'=>'two','permissions'=>['documents:update']];
$noPerm=['scope'=>'one','permissions'=>[]];
$doc=['id'=>42,'scope'=>'one','title'=>'old','owner_id'=>5];
$created=createDocument($one,['title'=>'draft','scope'=>'two']);
verify($created['scope']==='one' && $created['title']==='draft','untrusted scope used for create');
throws(AccessError::class,fn()=>createDocument($noPerm,['title'=>'draft']));
$out=updateDocument($one, $doc, ['title'=>'  new  ','scope'=>'two','owner_id'=>999]);
verify($out['title']==='new' && $out['scope']==='one' && $out['owner_id']===5, 'unsafe mass assignment');
throws(AccessError::class, fn()=> updateDocument($two,$doc,['title'=>'hack']));
throws(AccessError::class, fn()=> updateDocument($noPerm,$doc,['title'=>'hack']));
throws(InputError::class, fn()=> updateDocument($one,$doc,['title'=>'']));
throws(InputError::class, fn()=> updateDocument($one,$doc,['title'=>str_repeat('x',181)]));
echo "ALL CHECKS PASSED: allowed create data, scope denial, permission denial, boundary validation, field allowlist\n";
