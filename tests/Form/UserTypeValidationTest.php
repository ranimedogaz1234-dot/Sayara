<?php

namespace App\Tests\Form;

use App\Entity\User;
use App\Form\UserType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * Regression coverage for the profile-edit validation bug.
 *
 * User::$password carried Assert\Length(min: 8) with no group restriction,
 * so it ran under the Default group on every form submission - including a
 * profile edit where the password field was deliberately left blank (the
 * form's own placeholder: "Laisser vide pour ne pas changer"). Length
 * rejects the empty string, so saving a profile without changing the
 * password always failed with a validation error.
 *
 * The fix scopes Length to the 'registration' and 'password_change' groups,
 * and UserType now activates 'password_change' only when the submitted
 * password field is non-empty.
 *
 * This test is a plain TestCase: it builds the Form and Validator
 * components directly, boots no kernel, and opens no database connection.
 * User carries no UniqueEntity or other DB-backed constraint, so this is a
 * complete, isolated check of the validation rules - no "DB unavailable"
 * skip is needed because the test never attempts a connection.
 */
class UserTypeValidationTest extends TestCase
{
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $this->formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension($validator))
            ->getFormFactory();
    }

    private function freshUser(): User
    {
        $user = new User();
        $user->setUsername('victim');
        $user->setNom('Victim Test');
        $user->setEmail('victim@example.test');
        $user->setRole('ROLE_USER');
        // Simulates the stored hash ProfileController re-attaches before
        // validation; its exact value is irrelevant to these assertions.
        $user->setPassword('$2y$13$existingHashPlaceholder.......................');

        return $user;
    }

    /**
     * @param array<string, string> $submitted
     */
    private function submitProfileEdit(array $submitted): \Symfony\Component\Form\FormInterface
    {
        $form = $this->formFactory->create(UserType::class, $this->freshUser());
        $form->submit(array_merge([
            'username' => 'victim',
            'nom' => 'Victim Test',
            'email' => 'victim@example.test',
        ], $submitted));

        return $form;
    }

    /**
     * @param array<string, string> $submitted
     */
    private function submitRegistration(array $submitted): \Symfony\Component\Form\FormInterface
    {
        $form = $this->formFactory->create(UserType::class, new User(), [
            'validation_groups' => ['Default', 'registration'],
        ]);
        $form->submit(array_merge([
            'username' => 'newuser',
            'nom' => 'New User',
            'email' => 'new@example.test',
        ], $submitted));

        return $form;
    }

    public function testProfileEditWithBlankPasswordIsValid(): void
    {
        $form = $this->submitProfileEdit(['password' => '']);

        self::assertTrue($form->isValid(), 'leaving the password blank must not fail validation');
    }

    public function testProfileEditWithTooShortNewPasswordIsInvalid(): void
    {
        $form = $this->submitProfileEdit(['password' => 'short']);

        self::assertFalse($form->isValid(), 'a new password still has to meet the length requirement');
    }

    public function testProfileEditWithValidNewPasswordIsValid(): void
    {
        $form = $this->submitProfileEdit(['password' => 'BrandNewPass123']);

        self::assertTrue($form->isValid());
    }

    public function testRegistrationWithBlankPasswordIsInvalid(): void
    {
        $form = $this->submitRegistration(['password' => '']);

        self::assertFalse($form->isValid(), 'registration must still require a password');
    }

    public function testRegistrationWithTooShortPasswordIsInvalid(): void
    {
        $form = $this->submitRegistration(['password' => 'short']);

        self::assertFalse($form->isValid(), 'registration must still enforce the minimum length');
    }

    public function testRegistrationWithValidPasswordIsValid(): void
    {
        $form = $this->submitRegistration(['password' => 'ValidPass123']);

        self::assertTrue($form->isValid());
    }
}
